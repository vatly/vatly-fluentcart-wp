<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use DateTimeImmutable;
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
 *
 * Column mapping follows FluentCart's documented Subscription schema:
 * cancellation timestamp lives in `canceled_at`, grace-period end lives in
 * `expire_at`, the status enum uses `canceled` (not `cancelled`).
 */
final class FluentCartSubscriptionRepository implements SubscriptionRepositoryInterface
{
    /**
     * Loop-guard. Set to true while we're writing to a FluentCart subscription
     * row from inside a Vatly webhook reaction; checked by
     * {@see \Vatly\FluentCart\Plugin::propagateCancellation} so the outbound
     * cancel-at-Vatly path doesn't fire on inbound webhook-driven cancellations.
     */
    public static bool $suppressOutboundCancel = false;

    /**
     * Post-meta key on the FluentCart subscription row that records Vatly's
     * reason for a cancellation (`merchant_request` / `customer_request` /
     * `payment_failure`). Stored as meta rather than a native column to avoid
     * racing FluentCart's own subscription schema.
     */
    public const CANCELLATION_REASON_META = '_vatly_cancellation_reason';

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
        $vatlySubscription = $this->fetchVatlySubscription($data->vatlyId);
        $fluentCartSubId   = $this->subscriptionIdFromMetadata($vatlySubscription);

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

        $this->saveSuppressingOutbound($subscription, array_merge([
            'vendor_subscription_id' => $data->vatlyId,
            'vendor_customer_id'     => $data->customerId,
            'vendor_plan_id'         => $data->planId,
            'status'                 => 'active',
            'quantity'               => $data->quantity,
        ], $this->billingScheduleFrom($vatlySubscription)));

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
            // Resume: clear both the cancellation timestamp and the grace-end
            // date so FluentCart treats the subscription as actively renewing.
            $dirty['canceled_at'] = null;
            $dirty['expire_at']   = null;
            $dirty['status']      = 'active';
        } elseif ($data->endsAt !== null) {
            // Cancel: stamp the cancellation now, and let `expire_at` carry
            // the access end-date that Vatly returned. For immediate
            // cancellation the end-date is now; for grace-period cancellation
            // it's in the future and FluentCart will continue to grant access
            // until then.
            $dirty['canceled_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $dirty['expire_at']   = $data->endsAt->format('Y-m-d H:i:s');
            $dirty['status']      = 'canceled';
        }

        if ($dirty !== []) {
            $this->saveSuppressingOutbound($row, $dirty);
        }

        // Persist Vatly's cancellation reason alongside the end date so the
        // merchant can see *why* a subscription ended. Cancel webhooks —
        // including the hard nonpayment cancellation
        // (subscription.canceled_for_nonpayment → `payment_failure`, fired once
        // payment recovery is exhausted) — all carry this; other update events
        // leave it null and never touch the meta.
        if ($data->cancellationReason !== null) {
            $row->updateMeta(self::CANCELLATION_REASON_META, $data->cancellationReason);
        }

        return new FluentCartSubscription($row);
    }

    /**
     * @param array<string, mixed> $dirty
     */
    private function saveSuppressingOutbound(Subscription $row, array $dirty): void
    {
        self::$suppressOutboundCancel = true;
        try {
            $row->fill($dirty)->save();
        } finally {
            self::$suppressOutboundCancel = false;
        }
    }

    /**
     * Fetch the Vatly subscription resource. store() needs it both to read the
     * `fluentcart_subscription_id` we stamped in metadata and to back-fill the
     * billing schedule onto the FluentCart row.
     */
    private function fetchVatlySubscription(string $vatlySubscriptionId): ?object
    {
        try {
            return $this->plugin->vatly()->getSubscription()->execute($vatlySubscriptionId);
        } catch (Throwable $e) {
            error_log('[vatly-for-fluentcart] failed to fetch Vatly subscription: ' . $e->getMessage());
            return null;
        }
    }

    private function subscriptionIdFromMetadata(?object $vatlySubscription): ?int
    {
        if ($vatlySubscription === null) {
            return null;
        }

        $metadata = is_array($vatlySubscription->metadata ?? null)
            ? $vatlySubscription->metadata
            : (array) ($vatlySubscription->metadata ?? []);

        return isset($metadata['fluentcart_subscription_id'])
            ? (int) $metadata['fluentcart_subscription_id']
            : null;
    }

    /**
     * Back-fill FluentCart's billing-schedule columns from Vatly (the
     * authoritative source). FluentCart creates the subscription row at checkout
     * without a next billing date for a hosted-redirect gateway, so without this
     * the admin renders the next renewal as "Invalid Date". `next_billing_date`
     * is the next charge — during a trial that is the trial end, which Vatly's
     * `nextRenewalAt` already reflects, so we fall back to `trialUntil` when
     * `nextRenewalAt` is absent. `trial_ends_at` mirrors `trialUntil`. Columns
     * Vatly doesn't provide are left untouched.
     *
     * @return array<string, string>
     */
    private function billingScheduleFrom(?object $vatlySubscription): array
    {
        if ($vatlySubscription === null) {
            return [];
        }

        $trialUntil  = $this->toDateTime($vatlySubscription->trialUntil ?? null);
        $nextRenewal = $this->toDateTime($vatlySubscription->nextRenewalAt ?? null) ?? $trialUntil;

        $schedule = [];
        if ($nextRenewal !== null) {
            $schedule['next_billing_date'] = $nextRenewal;
        }
        if ($trialUntil !== null) {
            $schedule['trial_ends_at'] = $trialUntil;
        }

        return $schedule;
    }

    /**
     * Normalise a Vatly ISO-8601 timestamp to FluentCart's `Y-m-d H:i:s`
     * datetime column format (mirrors the cancel path's handling of expire_at).
     * Returns null for empty/unparseable input so the caller leaves the column
     * untouched.
     *
     * @param mixed $value
     */
    private function toDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function orphanWrapper(StoreSubscriptionData $data): SubscriptionInterface
    {
        $row = new Subscription();
        $row->vendor_subscription_id = $data->vatlyId;
        $row->vendor_customer_id     = $data->customerId;
        $row->vendor_plan_id         = $data->planId;
        $row->item_name              = $data->name;
        $row->quantity               = $data->quantity;
        $row->payment_mode           = $data->testmode ? 'test' : 'live';
        $row->status                 = 'orphaned';

        return new FluentCartSubscription($row);
    }
}
