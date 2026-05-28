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
        $endedAt = $this->subscription->ended_at ?? null;
        if ($endedAt === null || $endedAt === '') {
            return null;
        }
        return new DateTimeImmutable((string) $endedAt);
    }
}
