<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use FluentCart\App\Models\Subscription;
use Throwable;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;
use Vatly\FluentCart\Models\FluentCartSubscription;
use Vatly\FluentCart\Plugin;

/**
 * Bridges fluent's SubscriptionRepository contract to FluentCart's data layer.
 *
 * On first observation of a Vatly subscription (webhook flow inside
 * {@see \Vatly\Fluent\Webhooks\Reactions\SyncSubscriptionOnStarted}), looks
 * up the FluentCart subscription created at checkout via the metadata we
 * stamped (`fluentcart_subscription_id`) and back-fills the vendor IDs +
 * activates it.
 */
final class FluentCartSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(private Plugin $plugin) {}

    public function findByVatlyId(string $vatlyId): ?SubscriptionInterface
    {
        $subscription = Subscription::query()
            ->where('vendor_subscription_id', $vatlyId)
            ->first();

        return $subscription ? new FluentCartSubscription($subscription) : null;
    }

    public function store(StoreSubscriptionData $data): SubscriptionInterface
    {
        $fluentCartSubId = $this->fetchFluentCartSubscriptionId($data->vatlyId);

        if ($fluentCartSubId === null) {
            error_log(sprintf(
                '[vatly-for-fluentcart] subscription.started %s: no fluentcart_subscription_id in Vatly metadata',
                $data->vatlyId,
            ));
            return $this->orphanWrapper($data);
        }

        $subscription = Subscription::query()->find($fluentCartSubId);
        if (! $subscription) {
            error_log(sprintf(
                '[vatly-for-fluentcart] subscription.started %s: FluentCart subscription %d not found',
                $data->vatlyId,
                $fluentCartSubId,
            ));
            return $this->orphanWrapper($data);
        }

        $subscription->fill([
            'vendor_subscription_id' => $data->vatlyId,
            'vendor_customer_id'     => $data->customerId,
            'vendor_plan_id'         => $data->planId,
            'status'                 => 'active',
            'quantity'               => $data->quantity,
        ])->save();

        return new FluentCartSubscription($subscription);
    }

    public function update(SubscriptionInterface $subscription, UpdateSubscriptionData $data): SubscriptionInterface
    {
        if (! $subscription instanceof FluentCartSubscription) {
            return $subscription;
        }

        $row = $subscription->subscription;

        $dirty = array_filter([
            'vendor_plan_id' => $data->planId,
            'item_name'      => $data->name,
            'quantity'       => $data->quantity,
        ], fn ($v) => $v !== null);

        if ($data->clearEndsAt) {
            $dirty['ended_at'] = null;
            $dirty['status']   = 'active';
        } elseif ($data->endsAt !== null) {
            $dirty['ended_at'] = $data->endsAt->format('Y-m-d H:i:s');
            $dirty['status']   = $data->endsAt > new \DateTimeImmutable() ? 'cancelling' : 'cancelled';
        }

        if ($dirty !== []) {
            $row->fill($dirty)->save();
        }

        return new FluentCartSubscription($row);
    }

    private function fetchFluentCartSubscriptionId(string $vatlySubscriptionId): ?int
    {
        try {
            $sub = $this->plugin->vatly()->getSubscription()->execute($vatlySubscriptionId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] failed to fetch Vatly subscription metadata: ' . $e->getMessage());
            return null;
        }

        $metadata = is_array($sub->metadata ?? null) ? $sub->metadata : (array) ($sub->metadata ?? []);

        return isset($metadata['fluentcart_subscription_id'])
            ? (int) $metadata['fluentcart_subscription_id']
            : null;
    }

    private function orphanWrapper(StoreSubscriptionData $data): SubscriptionInterface
    {
        $row = new Subscription();
        $row->vendor_subscription_id = $data->vatlyId;
        $row->vendor_customer_id     = $data->customerId;
        $row->vendor_plan_id         = $data->planId;
        $row->item_name              = $data->name;
        $row->quantity               = $data->quantity;
        $row->status                 = 'orphaned';

        return new FluentCartSubscription($row);
    }
}
