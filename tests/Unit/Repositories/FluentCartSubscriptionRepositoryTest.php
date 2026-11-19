<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Repositories;

use DateTimeImmutable;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Mockery;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Data\UpdateSubscriptionData;
use Vatly\FluentCart\Models\FluentCartSubscription;
use Vatly\FluentCart\PaymentMethod\VatlySubscriptions;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Repositories\FluentCartSubscriptionRepository;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Unit coverage of FluentCartSubscriptionRepository::update() and the
 * outbound-cancel loop-guard. store()-side routing (metadata fetch +
 * Subscription::query()->find) is left to integration tests (PR C).
 *
 * @covers \Vatly\FluentCart\Repositories\FluentCartSubscriptionRepository
 */
class FluentCartSubscriptionRepositoryTest extends TestCase
{
    public function test_update_short_circuits_for_foreign_subscription_interface(): void
    {
        $foreign = Mockery::mock(SubscriptionInterface::class);

        $result = (new FluentCartSubscriptionRepository($this->plugin()))->update(
            $foreign,
            new UpdateSubscriptionData(planId: 'plan_new'),
        );

        self::assertSame($foreign, $result);
    }

    /**
     * The repository's update() never reads from Plugin, but the constructor
     * is typed against the final class — so we bypass the private constructor
     * via reflection rather than trying to mock the unmockable.
     */
    private function plugin(): Plugin
    {
        return (new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
    }

    public function test_billing_schedule_maps_vatly_trial_and_next_renewal(): void
    {
        // Regression for "Next renewal: Invalid Date": FluentCart creates the
        // subscription row at checkout without a next billing date for a
        // hosted-redirect gateway, so store() back-fills it from Vatly's
        // authoritative dates. A null column renders as "Invalid Date".
        $schedule = $this->billingSchedule((object) [
            'trialUntil'    => '2026-11-09T14:41:37+00:00',
            'nextRenewalAt' => '2026-11-09T14:41:37+00:00',
        ]);

        self::assertSame('2026-11-09 14:41:37', $schedule['next_billing_date']);
        self::assertSame('2026-11-09 14:41:37', $schedule['trial_ends_at']);
    }

    public function test_billing_schedule_falls_back_to_trial_until_for_next_billing(): void
    {
        // During a trial the next charge is the trial end, so when Vatly reports
        // trialUntil but no separate nextRenewalAt, next_billing_date falls back
        // to the trial end rather than being left null.
        $schedule = $this->billingSchedule((object) [
            'trialUntil'    => '2026-12-01T00:00:00+00:00',
            'nextRenewalAt' => null,
        ]);

        self::assertSame('2026-12-01 00:00:00', $schedule['next_billing_date']);
        self::assertSame('2026-12-01 00:00:00', $schedule['trial_ends_at']);
    }

    public function test_billing_schedule_is_empty_when_vatly_gives_no_dates(): void
    {
        // No dates yet: leave the columns untouched rather than writing bogus
        // values.
        $schedule = $this->billingSchedule((object) ['trialUntil' => null, 'nextRenewalAt' => null]);

        self::assertSame([], $schedule);
    }

    /**
     * Exercise the private billing-schedule mapper directly — it's a pure
     * function of the Vatly subscription resource, so no API seam is needed.
     *
     * @return array<string, string>
     */
    private function billingSchedule(object $vatlySubscription): array
    {
        $method = new \ReflectionMethod(FluentCartSubscriptionRepository::class, 'billingScheduleFrom');
        $method->setAccessible(true);

        /** @var array<string, string> $result */
        $result = $method->invoke(new FluentCartSubscriptionRepository($this->plugin()), $vatlySubscription);

        return $result;
    }

    public function test_backfill_billing_schedule_skips_a_row_with_no_vatly_id(): void
    {
        // The backfill query only selects rows with a vendor id, but guard the
        // entry point anyway: with nothing to resolve it returns false before
        // ever reaching the Vatly API seam, so an un-linked row is a safe no-op.
        $repository = new FluentCartSubscriptionRepository($this->plugin());

        $empty                         = new Subscription();
        $empty->vendor_subscription_id = '';
        self::assertFalse($repository->backfillBillingSchedule($empty));

        $null                         = new Subscription();
        $null->vendor_subscription_id = null;
        self::assertFalse($repository->backfillBillingSchedule($null));
    }

    public function test_update_writes_plan_name_and_quantity_when_provided(): void
    {
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->once()->with([
            'vendor_plan_id' => 'plan_new',
            'item_name'      => 'Bigger Plan',
            'quantity'       => 3,
        ])->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(planId: 'plan_new', name: 'Bigger Plan', quantity: 3),
        );

        $this->assertHookExpectations();
    }

    public function test_cancel_goes_through_fluentcart_and_keeps_access_until_vatlys_end_date(): void
    {
        // FluentCart keeps a canceled subscription's access until
        // next_billing_date and expires it after, so that date carries Vatly's
        // end date. The write goes through syncSubscriptionStates so FluentCart's
        // subscription_canceled hooks and emails fire.
        $row = new Subscription();
        $row->id = 7;
        $row->status = 'active';
        $row->next_billing_date = '2026-12-01 00:00:00';

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(endsAt: new DateTimeImmutable('2026-12-31 00:00:00')),
        );

        self::assertCount(1, SubscriptionService::$synced);
        self::assertSame('canceled', $row->status);
        self::assertSame('2026-12-31 00:00:00', $row->next_billing_date);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row->canceled_at);
    }

    public function test_cancel_webhook_during_fluentcarts_own_cancel_leaves_the_status_to_fluentcart(): void
    {
        // The admin cancel is still running when Vatly's webhook lands. That
        // request writes `canceled` and fires the cancel event itself, so the
        // webhook records only the end date and the event fires once.
        $row = new Subscription();
        $row->id = 7;
        $row->status = 'past_due';
        set_transient(VatlySubscriptions::cancelInFlightKey(7), 1, 120);

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(endsAt: new DateTimeImmutable('2026-12-31 00:00:00')),
        );

        self::assertSame('past_due', $row->status);
        self::assertSame('2026-12-31 00:00:00', $row->next_billing_date);
    }

    public function test_update_persists_cancellation_reason_meta_when_provided(): void
    {
        // A cancel webhook carries both the end date and the reason. The reason
        // is stored as post meta (not a native column) so the merchant can see
        // *why* the subscription ended.
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->once()->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);
        $row->shouldReceive('updateMeta')->once()->with(
            FluentCartSubscriptionRepository::CANCELLATION_REASON_META,
            'payment_failure',
        );

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(
                endsAt: new DateTimeImmutable('2026-12-31 00:00:00'),
                cancellationReason: 'payment_failure',
            ),
        );

        $this->assertHookExpectations();
    }

    public function test_update_without_cancellation_reason_does_not_write_meta(): void
    {
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->once()->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);
        $row->shouldNotReceive('updateMeta');

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(endsAt: new DateTimeImmutable('2026-12-31 00:00:00')),
        );

        $this->assertHookExpectations();
    }

    public function test_resume_goes_through_fluentcart_and_reactivates(): void
    {
        // clearEndsAt is the resume path, and wins over a stray endsAt.
        $row = new Subscription();
        $row->id = 7;
        $row->status = 'canceled';
        $row->canceled_at = '2026-11-01 00:00:00';

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(endsAt: new DateTimeImmutable('+10 days'), clearEndsAt: true),
        );

        self::assertCount(1, SubscriptionService::$synced);
        self::assertSame('active', $row->status);
        self::assertNull($row->canceled_at);
    }

    public function test_update_with_no_changes_does_not_touch_the_row(): void
    {
        $row = Mockery::mock(Subscription::class);
        $row->shouldNotReceive('fill');
        $row->shouldNotReceive('save');

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(),
        );

        $this->assertHookExpectations();
    }

    public function test_store_activates_the_checkout_subscription_once(): void
    {
        $row = new Subscription();
        $row->id = 55;
        $row->status = 'pending';
        $row->current_payment_method = 'vatly';

        $plugin = $this->pluginReturningSubscription($this->vatlySubscription('subscription_abc', [
            'metadata'      => (object) ['fluentcart_subscription_id' => '55'],
            'nextRenewalAt' => '2026-12-19T10:00:00+00:00',
        ]));
        $data = new \Vatly\Fluent\Data\StoreSubscriptionData(
            vatlyId: 'subscription_abc',
            customerId: 'customer_1',
            type: 'default',
            planId: 'subscription_plan_1',
            name: 'Pro',
            testmode: true,
        );

        \FluentCart\App\Builder::$nextResults = [$row];
        (new FluentCartSubscriptionRepository($plugin))->store($data);

        self::assertSame('active', $row->status);
        self::assertSame('subscription_abc', $row->vendor_subscription_id);
        self::assertSame('2026-12-19 10:00:00', $row->next_billing_date);
        self::assertCount(1, SubscriptionService::$synced, 'through FluentCart, so its status hooks fire');
        self::assertCount(1, SubscriptionActivated::$dispatched);

        // A late re-delivery neither announces it again nor revives a
        // subscription canceled in the meantime.
        $row->status = 'canceled';
        \FluentCart\App\Builder::$nextResults = [$row];
        (new FluentCartSubscriptionRepository($plugin))->store($data);

        self::assertSame('canceled', $row->status);
        self::assertCount(1, SubscriptionActivated::$dispatched);
    }

    private function pluginReturningSubscription(\Vatly\API\Resources\Subscription $subscription): Plugin
    {
        $getSubscription = Mockery::mock(\Vatly\Fluent\Actions\GetSubscription::class);
        $getSubscription->shouldReceive('execute')->with($subscription->id)->andReturn($subscription);

        $vatly = Mockery::mock(\Vatly\Fluent\Vatly::class);
        $vatly->shouldReceive('getSubscription')->andReturn($getSubscription);

        $ref    = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $prop   = $ref->getProperty('vatly');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $vatly);

        return $plugin;
    }
}
