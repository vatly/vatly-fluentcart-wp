<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Models;

use DateTimeImmutable;
use DateTimeInterface;
use FluentCart\App\Models\Subscription;
use Vatly\Fluent\Concerns\DerivesSubscriptionState;
use Vatly\Fluent\Contracts\SubscriptionInterface;

/**
 * {@see SubscriptionInterface} adapter wrapping a FluentCart Subscription.
 *
 * Uses {@see DerivesSubscriptionState} for the five derived predicates so the
 * adapter only has to expose the underlying state accessors.
 */
final class FluentCartSubscription implements SubscriptionInterface
{
    use DerivesSubscriptionState;

    public function __construct(public readonly Subscription $subscription) {}

    public function getVatlyId(): string
    {
        return (string) ($this->subscription->vendor_subscription_id ?? '');
    }

    public function getType(): string
    {
        return (string) ($this->subscription->subscription_type ?? 'default');
    }

    public function getPlanId(): string
    {
        return (string) ($this->subscription->vendor_plan_id ?? '');
    }

    public function getName(): string
    {
        return (string) ($this->subscription->item_name ?? $this->subscription->name ?? '');
    }

    public function getQuantity(): int
    {
        return (int) ($this->subscription->quantity ?? 1);
    }

    public function getEndsAt(): ?DateTimeInterface
    {
        // FluentCart's grace-period end lives in `expire_at`; absent until the
        // subscription has been canceled or expired (DerivesSubscriptionState
        // treats a null here as "not cancelled").
        $expireAt = $this->subscription->expire_at ?? null;
        if ($expireAt === null || $expireAt === '') {
            return null;
        }
        return new DateTimeImmutable((string) $expireAt);
    }

    /**
     * Vatly persists the mandate (payment method on file) on every webhook
     * delivery so consumer billing portals can render "card ending in 4242"
     * without a per-page-load API roundtrip. FluentCart already surfaces
     * payment-method info from its own checkout pipeline, so writing the
     * same fact into a column we don't own would race FluentCart's display
     * and risk drift.
     *
     * Returning null here is the documented "no mandate persisted locally"
     * answer; vatly-fluent-php's `sync()` semantics treat that as the
     * conservative case and won't overwrite anything host-side.
     *
     * Follow-up: surface the Vatly mandate alongside FluentCart's view in
     * the subscription admin so the merchant sees both perspectives without
     * a Vatly dashboard round-trip.
     */
    public function getMandateMethod(): ?string
    {
        return null;
    }

    public function getMandateMaskedIdentifier(): ?string
    {
        return null;
    }
}
