<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use Brain\Monkey\Functions;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\PaymentInstance;
use Mockery;
use Vatly\Fluent\Builders\SubscriptionBuilder;
use Vatly\Fluent\Testing\FakeCheckout;
use Vatly\Fluent\Vatly;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\Processor;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;

/**
 * The Processor must carry FluentCart's configured trial over to the Vatly
 * subscription, so the first charge lands after the trial — not at checkout.
 *
 * FluentCart's `fct_subscriptions` row exposes `trial_days` (whole-day count,
 * 0 = no trial) and `trial_ends_at` (datetime, null = no trial). We assert the
 * day-count wins, the end-date is the documented fallback, and a trial-less
 * subscription leaves the builder untouched.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\Processor
 */
class ProcessorTrialTest extends TestCase
{
    public function test_subscription_with_trial_days_calls_with_trial_days(): void
    {
        $builder = $this->expectBuilder();
        $builder->shouldReceive('withTrialDays')->once()->with(14)->andReturnSelf();
        $builder->shouldNotReceive('withTrialEndsAt');

        $subscription = new Subscription();
        $subscription->id = 99;
        $subscription->trial_days = 14;

        $result = $this->process($builder, $subscription);

        self::assertTrue($result['success']);
    }

    public function test_subscription_with_trial_ends_at_only_falls_back_to_with_trial_ends_at(): void
    {
        $builder = $this->expectBuilder();
        $builder->shouldNotReceive('withTrialDays');
        $builder->shouldReceive('withTrialEndsAt')->once()
            ->with(Mockery::type(\DateTimeInterface::class))->andReturnSelf();

        $subscription = new Subscription();
        $subscription->id = 99;
        $subscription->trial_days = 0;
        $subscription->trial_ends_at = (new \DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s');

        $result = $this->process($builder, $subscription);

        self::assertTrue($result['success']);
    }

    public function test_subscription_without_trial_sets_no_trial(): void
    {
        $builder = $this->expectBuilder();
        $builder->shouldNotReceive('withTrialDays');
        $builder->shouldNotReceive('withTrialEndsAt');

        $subscription = new Subscription();
        $subscription->id = 99;
        $subscription->trial_days = 0;
        $subscription->trial_ends_at = null;

        $result = $this->process($builder, $subscription);

        self::assertTrue($result['success']);
    }

    public function test_subscription_with_past_trial_ends_at_sets_no_trial(): void
    {
        $builder = $this->expectBuilder();
        $builder->shouldNotReceive('withTrialDays');
        $builder->shouldNotReceive('withTrialEndsAt');

        $subscription = new Subscription();
        $subscription->id = 99;
        $subscription->trial_days = 0;
        $subscription->trial_ends_at = (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s');

        $result = $this->process($builder, $subscription);

        self::assertTrue($result['success']);
    }

    /**
     * A SubscriptionBuilder mock with the non-trial chain pre-staged. Individual
     * tests layer the trial expectations on top.
     */
    private function expectBuilder(): Mockery\MockInterface
    {
        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('toPlan')->once()->andReturnSelf();
        $builder->shouldReceive('withQuantity')->once()->andReturnSelf();
        $builder->shouldReceive('withRedirectUrlSuccess')->once()->andReturnSelf();
        $builder->shouldReceive('withRedirectUrlCanceled')->once()->andReturnSelf();
        $builder->shouldReceive('create')->once()->andReturn(FakeCheckout::make());

        return $builder;
    }

    /**
     * Drive Processor::process() with the given subscription, wiring a Plugin
     * whose Vatly client hands back the supplied (trial-expecting) builder.
     */
    private function process(Mockery\MockInterface $builder, Subscription $subscription): array
    {
        Functions\when('get_option')->justReturn([
            'is_active'           => true,
            'payment_mode'        => 'test',
            'test_api_key'        => 'test_key',
            'test_webhook_secret' => 'whsec',
        ]);
        Functions\when('get_post_meta')->justReturn('subscription_plan_42');

        $vatly = Mockery::mock(Vatly::class);
        $vatly->shouldReceive('subscriptionBuilder')->once()->andReturn($builder);

        $plugin = $this->pluginWith($vatly);

        $order = new \stdClass();
        $order->id = 7;
        $order->customer = null;             // anonymous-checkout: skips customers()
        $order->email = 'buyer@example.test';
        $order->customer_name = 'Buyer';
        $order->items = [(object) ['post_id' => 100, 'quantity' => 2]];
        $order->payment_redirect_url = 'https://shop.test/return';

        $instance = new PaymentInstance();
        $instance->order = $order;
        $instance->transaction = $this->transactionStub();
        $instance->subscription = $subscription;

        return (new Processor($plugin))->process($instance);
    }

    /**
     * Build a Plugin without invoking its private constructor, then inject the
     * mocked Vatly client and a real (configured) VatlyConfig via reflection —
     * the same unmockable-final workaround the repository tests use.
     */
    private function pluginWith(Vatly $vatly): Plugin
    {
        $ref = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($plugin, new VatlyConfig());

        $client = $ref->getProperty('vatly');
        $client->setAccessible(true);
        $client->setValue($plugin, $vatly);

        return $plugin;
    }

    private function transactionStub(): object
    {
        return new class {
            public int $id = 5;
            public string $uuid = 'txn-uuid';
            public ?string $vendor_charge_id = null;

            /** @param array<string, mixed> $attrs */
            public function fill(array $attrs): self
            {
                $this->vendor_charge_id = $attrs['vendor_charge_id'] ?? $this->vendor_charge_id;

                return $this;
            }

            public function save(): bool
            {
                return true;
            }
        };
    }
}
