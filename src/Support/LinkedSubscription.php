<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use FluentCart\App\Models\Subscription;
use Vatly\API\Resources\Order;
use Vatly\API\Types\OrderLineData;

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
 * Only then may a caller fall back to its older lookup (such as the
 * customer's newest subscription). When a line names a subscription, that
 * subscription is the only one a caller may act on: if it is not known
 * locally, the caller must not pick a different one.
 */
final class LinkedSubscription
{
    /**
     * The local Vatly subscription the order's lines link to, or null when no
     * line carries a link or no local subscription has the linked id.
     */
    public static function forOrder(Order $order): ?Subscription
    {
        return self::forLines($order->lines());
    }

    /**
     * Same as {@see self::forOrder()}, for lines a webhook event already
     * carries.
     *
     * @param iterable<int, OrderLineData|\Vatly\API\Resources\OrderLine> $lines
     */
    public static function forLines(iterable $lines): ?Subscription
    {
        $vatlySubscriptionId = self::vatlySubscriptionId($lines);
        if ($vatlySubscriptionId === null) {
            return null;
        }

        return self::find($vatlySubscriptionId);
    }

    /**
     * The local Vatly subscription with this Vatly subscription id, or null
     * when FluentCart doesn't know it.
     */
    public static function find(string $vatlySubscriptionId): ?Subscription
    {
        return Subscription::query()
            ->where('vendor_subscription_id', $vatlySubscriptionId)
            ->where('current_payment_method', 'vatly')
            ->first();
    }

    /**
     * The Vatly subscription id the lines link to. A Vatly order belongs to
     * one subscription, so the first link decides.
     *
     * @param iterable<int, OrderLineData|\Vatly\API\Resources\OrderLine> $lines
     */
    public static function vatlySubscriptionId(iterable $lines): ?string
    {
        foreach ($lines as $line) {
            $vatlySubscriptionId = (string) ($line->relatedResources->subscriptionId ?? '');
            if ($vatlySubscriptionId !== '') {
                return $vatlySubscriptionId;
            }
        }

        return null;
    }
}
