<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Repositories;

use DateTimeImmutable;
use FluentCart\App\Models\Subscription;
use Mockery;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Data\UpdateSubscriptionData;
use Vatly\FluentCart\Models\FluentCartSubscription;
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

    public function test_update_with_endsAt_stamps_cancellation_and_status_canceled(): void
    {
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->once()->with(Mockery::on(static function (array $dirty): bool {
            return $dirty['status'] === 'canceled'
                && $dirty['expire_at'] === '2026-12-31 00:00:00'
                && isset($dirty['canceled_at'])
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dirty['canceled_at']) === 1;
        }))->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(endsAt: new DateTimeImmutable('2026-12-31 00:00:00')),
        );

        $this->assertHookExpectations();
    }

    public function test_update_with_clearEndsAt_resumes_to_active(): void
    {
        // clearEndsAt is the resume path: blank both timestamps and flip to active.
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->once()->with([
            'canceled_at' => null,
            'expire_at'   => null,
            'status'      => 'active',
        ])->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(clearEndsAt: true),
        );

        $this->assertHookExpectations();
    }

    public function test_update_clearEndsAt_takes_precedence_over_endsAt(): void
    {
        // If a caller passes both clearEndsAt=true and endsAt=<some date>,
        // clearEndsAt wins. (The data class doesn't enforce mutual exclusion
        // so the repository's `if/elseif` order is what matters.)
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->once()->with([
            'canceled_at' => null,
            'expire_at'   => null,
            'status'      => 'active',
        ])->andReturnSelf();
        $row->shouldReceive('save')->once()->andReturn(true);

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(
                endsAt: new DateTimeImmutable('+10 days'),
                clearEndsAt: true,
            ),
        );

        $this->assertHookExpectations();
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

    public function test_update_flips_outbound_cancel_loop_guard_around_save(): void
    {
        // While save() runs we should be inside the loop guard. Once it
        // returns (success OR exception), the flag must be cleared.
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->andReturnSelf();
        $row->shouldReceive('save')->andReturnUsing(function (): bool {
            self::assertTrue(
                FluentCartSubscriptionRepository::$suppressOutboundCancel,
                'Loop guard should be true while saving inbound webhook updates.',
            );
            return true;
        });

        self::assertFalse(FluentCartSubscriptionRepository::$suppressOutboundCancel, 'guard should default to false');

        (new FluentCartSubscriptionRepository($this->plugin()))->update(
            new FluentCartSubscription($row),
            new UpdateSubscriptionData(planId: 'plan_x'),
        );

        self::assertFalse(FluentCartSubscriptionRepository::$suppressOutboundCancel, 'guard should be cleared after save');
    }

    public function test_loop_guard_is_cleared_when_save_throws(): void
    {
        $row = Mockery::mock(Subscription::class);
        $row->shouldReceive('fill')->andReturnSelf();
        $row->shouldReceive('save')->andThrow(new \RuntimeException('db down'));

        try {
            (new FluentCartSubscriptionRepository($this->plugin()))->update(
                new FluentCartSubscription($row),
                new UpdateSubscriptionData(planId: 'plan_x'),
            );
            self::fail('Expected exception');
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertFalse(
            FluentCartSubscriptionRepository::$suppressOutboundCancel,
            'finally{} must clear the guard even on save exception',
        );
    }
}
