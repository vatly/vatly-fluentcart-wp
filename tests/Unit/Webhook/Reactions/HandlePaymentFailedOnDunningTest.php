<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Webhook\Reactions;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use FluentCart\App\Builder;
use FluentCart\App\Models\Subscription;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\OrderPaymentFailed;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\FluentCart\Webhook\Reactions\HandlePaymentFailedOnDunning;

/**
 * Feature coverage for issue #2: an `order.payment_failed` webhook must flip
 * the attributed FluentCart subscription to FluentCart's `past_due` dunning
 * status and fire the native dunning notification hook.
 *
 * Routes the typed {@see OrderPaymentFailed} event through the reaction the
 * exact same way {@see \Vatly\Fluent\Webhooks\WebhookProcessor::handle()} does
 * (supports() gate + handle()). The two resolution paths — checkout-stamped
 * subscription id and customer-binding fallback — are both exercised
 * end-to-end against the FluentCart Subscription stub.
 *
 * @covers \Vatly\FluentCart\Webhook\Reactions\HandlePaymentFailedOnDunning
 */
final class HandlePaymentFailedOnDunningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Builder::reset();
    }

    protected function tearDown(): void
    {
        Builder::reset();
        parent::tearDown();
    }

    private function event(string $customerId = 'cus_123', ?array $metadata = null): OrderPaymentFailed
    {
        return new OrderPaymentFailed(
            customerId: $customerId,
            orderId: 'ord_failed_1',
            status: 'pending',
            total: 1999,
            subtotal: 1652,
            taxSummary: new TaxSummaryCollection([]),
            currency: 'EUR',
            invoiceNumber: null,
            paymentMethod: 'card',
            metadata: $metadata,
        );
    }

    public function test_flips_attributed_subscription_to_past_due_via_checkout_metadata(): void
    {
        $row = new Subscription();
        $row->id = 55;
        $row->status = 'active';
        $row->payment_method = 'vatly';

        // The checkout-stamped path looks the subscription up by id directly.
        Builder::$nextResults = [$row];

        $bindings = \Mockery::mock(CustomerBindingRepository::class);
        $bindings->shouldNotReceive('hostCustomerIdFor');

        $reaction = new HandlePaymentFailedOnDunning($bindings);

        Actions\expectDone('fluent_cart/payments/subscription_past_due')->once();
        Actions\expectDone('vatly_fluentcart_subscription_past_due')->once();

        self::assertTrue($reaction->supports($this->event(metadata: ['fluentcart_subscription_id' => 55])));
        $reaction->handle($this->event(metadata: ['fluentcart_subscription_id' => 55]));

        self::assertSame('past_due', $row->status, 'subscription must flip to FluentCart past_due');
    }

    public function test_flips_subscription_via_customer_binding_fallback(): void
    {
        // The renewal-failure common case: no checkout-stamped subscription id,
        // resolve via the Vatly→host customer binding, then most-recent active
        // subscription for that customer.
        $row = new Subscription();
        $row->id = 77;
        $row->status = 'active';
        $row->payment_method = 'vatly';

        Builder::$nextResults = [$row];

        $bindings = \Mockery::mock(CustomerBindingRepository::class);
        $bindings->shouldReceive('hostCustomerIdFor')->once()->with('cus_123')->andReturn('42');

        $reaction = new HandlePaymentFailedOnDunning($bindings);

        Actions\expectDone('fluent_cart/payments/subscription_past_due')->once();

        $reaction->handle($this->event());

        self::assertSame('past_due', $row->status);
    }

    public function test_redelivery_is_idempotent_and_does_not_refire_notification(): void
    {
        // A subscription already in dunning (past_due) gets a re-delivered
        // order.payment_failed. It must stay past_due but NOT re-fire the
        // merchant notification.
        $row = new Subscription();
        $row->id = 55;
        $row->status = 'past_due';
        $row->payment_method = 'vatly';

        Builder::$nextResults = [$row];

        $bindings = \Mockery::mock(CustomerBindingRepository::class);

        $reaction = new HandlePaymentFailedOnDunning($bindings);

        Actions\expectDone('fluent_cart/payments/subscription_past_due')->never();
        Actions\expectDone('vatly_fluentcart_subscription_past_due')->never();

        $reaction->handle($this->event(metadata: ['fluentcart_subscription_id' => 55]));

        self::assertSame('past_due', $row->status);
    }

    public function test_logs_and_no_ops_when_no_subscription_can_be_attributed(): void
    {
        // Empty result queue → query returns null on every hop.
        $bindings = \Mockery::mock(CustomerBindingRepository::class);
        $bindings->shouldReceive('hostCustomerIdFor')->once()->andReturn(null);

        Functions\expect('error_log')->once();
        Actions\expectDone('fluent_cart/payments/subscription_past_due')->never();

        $reaction = new HandlePaymentFailedOnDunning($bindings);
        $reaction->handle($this->event());

        $this->assertHookExpectations();
    }
}
