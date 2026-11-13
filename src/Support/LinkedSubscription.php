<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Subscription;
use Vatly\API\Resources\Order;

/**
 * Resolves the FluentCart subscription a Vatly order belongs to from the
 * order's own lines.
 *
 * Vatly links each subscription order line (initial signup, renewal, immediate
 * update) to its subscription in `lines[].relatedResources.subscriptionId`.
 * FluentCart stores that Vatly id as `vendor_subscription_id`, so the link
 * names one local subscription exactly. That beats guessing "the customer's
 * newest active subscription", which picks the wrong one when a customer
 * has more than one.
 *
 * The link is null on one-off lines, on orders from before Vatly shipped it,
 * and on an initial signup line until Vatly has created the subscription.
 * Callers fall back to their previous lookup when this returns null.
 */
final class LinkedSubscription
{
    /**
     * The local Vatly subscription the order's lines link to, or null when no
     * line carries a link or no local subscription has the linked id.
     */
    public static function forOrder(Order $order): ?Subscription
    {
        foreach ($order->lines() as $line) {
            $vatlySubscriptionId = (string) ($line->relatedResources->subscriptionId ?? '');
            if ($vatlySubscriptionId === '') {
                continue;
            }

            // A Vatly order belongs to one subscription, so the first link decides.
            return Subscription::query()
                ->where('vendor_subscription_id', $vatlySubscriptionId)
                ->where('payment_method', 'vatly')
                ->first();
        }

        return null;
    }
}
