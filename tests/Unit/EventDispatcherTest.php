<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit;

use Brain\Monkey\Actions;
use Vatly\API\Types\Money;
use Vatly\API\Webhooks\Events\SubscriptionUpdated;
use Vatly\API\Webhooks\Events\SubscriptionUpdateScheduled;
use Vatly\API\Types\ScheduledSubscriptionUpdate;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\FluentCart\Webhook\EventDispatcher;

/**
 * The dispatcher derives a WordPress action hook from each event class, so new
 * fluent events surface to site code without a dispatcher change. These lock the
 * hook names for the two subscription-update events added in fluent-php alpha.23.
 */
final class EventDispatcherTest extends TestCase
{
    public function test_subscription_updated_bridges_to_its_derived_hook_and_the_catch_all(): void
    {
        Actions\expectDone('vatly_fluentcart_subscription_updated')->once();
        Actions\expectDone('vatly_fluentcart_event')->once();

        (new EventDispatcher())->dispatch(new SubscriptionUpdated(
            customerId: 'cus_1',
            subscriptionId: 'sub_1',
            planId: 'plan_pro',
            name: 'Pro',
            description: 'Pro plan',
            basePrice: new Money('EUR', '29.00'),
            quantity: 1,
            interval: 'month',
            intervalCount: 1,
            testmode: true,
        ));

        $this->assertHookExpectations();
    }

    public function test_subscription_update_scheduled_bridges_to_its_derived_hook(): void
    {
        Actions\expectDone('vatly_fluentcart_subscription_update_scheduled')->once();
        Actions\expectDone('vatly_fluentcart_event')->once();

        (new EventDispatcher())->dispatch(new SubscriptionUpdateScheduled(
            'cus_1',
            'sub_1',
            true,
            new ScheduledSubscriptionUpdate('plan_pro', 'Pro Annual', 'desc', new Money('EUR', '290.00'), 1, 'year', 1),
        ));

        $this->assertHookExpectations();
    }
}
