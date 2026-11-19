<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Repositories;

use DateTimeImmutable;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use Throwable;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;
use Vatly\FluentCart\Models\FluentCartSubscription;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\PaymentMethod\VatlySubscriptions;

/**
 * Bridges fluent's SubscriptionRepository contract to FluentCart's data layer.
 *
 * On first observation of a Vatly subscription (webhook flow inside
 * {@see \Vatly\Fluent\Webhooks\Reactions\SyncSubscriptionOnStarted}), looks
 * up the FluentCart subscription created at checkout via the metadata we
 * stamped (`fluentcart_subscription_id`) and back-fills the vendor IDs +
 * activates it.
 *
 * Every write goes through FluentCart's
 * `SubscriptionService::syncSubscriptionStates()`, the method FluentCart's own
 * gateways use, so status changes fire FluentCart's subscription hooks
 * (`fluent_cart/payments/subscription_{status}`, status_changed, canceled,
 * reactivated). A canceled subscription keeps access until
 * `next_billing_date`, so a cancellation sets that column to Vatly's end date.
 */
final class FluentCartSubscriptionRepository implements SubscriptionRepositoryInterface
{
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
            // The usual case: the initial order.paid links and activates it,
            // whichever of the two webhooks arrives first.
            return $this->findByVatlyId($data->vatlyId) ?? $this->orphanWrapper($data);
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

        return new FluentCartSubscription($this->activate($subscription, $data->vatlyId, $vatlySubscription));
    }

    /**
     * Link a FluentCart subscription to its Vatly subscription and activate it.
     *
     * Vatly doesn't copy checkout metadata onto the subscription, so store()
     * usually can't name the FluentCart row. The initial paid order can: its
     * metadata names the FluentCart subscription and its line links the Vatly
     * one. {@see FluentCartOrderRepository} calls this from there.
     *
     * @param object|null $vatlySubscription The Vatly subscription resource, when already fetched.
     */
    public function activate(Subscription $subscription, string $vatlySubscriptionId, ?object $vatlySubscription = null, ?bool $testmode = null): Subscription
    {
        $vatlySubscription ??= $this->fetchVatlySubscription($vatlySubscriptionId, $testmode);

        $schedule   = $this->billingScheduleFrom($vatlySubscription);
        $activating = in_array((string) $subscription->status, ['pending', 'intended'], true);
        $args       = array_merge(array_filter([
            'vendor_subscription_id' => $vatlySubscriptionId,
            'vendor_customer_id'     => $vatlySubscription->customerId ?? null,
            'vendor_plan_id'         => $vatlySubscription->subscriptionPlanId ?? null,
            'quantity'               => $vatlySubscription->quantity ?? null,
        ], fn ($v) => $v !== null), $schedule);

        // Only the first activation sets the status. A late re-delivery must
        // not revive a subscription that has since been canceled or paused.
        if ($activating) {
            $trialing       = isset($schedule['trial_ends_at']) && strtotime($schedule['trial_ends_at']) > time();
            $args['status'] = $trialing ? 'trialing' : 'active';
        }

        $subscription = $this->sync($subscription, $args);

        // Activation event, as FluentCart's own gateways dispatch it: once, on the
        // pending -> active move.
        if ($activating) {
            (new SubscriptionActivated($subscription, $subscription->order, $subscription->customer))->dispatch();
        }

        return $subscription;
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
            // Resume: active again, renewing on Vatly's schedule.
            $dirty['canceled_at'] = null;
            $dirty['status']      = 'active';
            $dirty = array_merge($dirty, $this->billingScheduleFrom(
                $this->fetchVatlySubscription((string) $row->vendor_subscription_id)
            ));
        } elseif ($data->endsAt !== null) {
            // Cancel: FluentCart keeps a canceled subscription's access until
            // `next_billing_date` and expires it after that, so that column
            // carries Vatly's end date (now for an immediate cancellation, the
            // period end for a grace-period one).
            // While FluentCart itself is cancelling it (admin or customer
            // portal), that request sets the status and fires the cancel
            // event; this webhook only records the end date.
            if (! get_transient(VatlySubscriptions::cancelInFlightKey((int) ($row->id ?? 0)))) {
                $dirty['status'] = 'canceled';
            }
            $dirty['canceled_at']       = $row->canceled_at ?: (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $dirty['next_billing_date'] = $data->endsAt->format('Y-m-d H:i:s');
        }

        if ($dirty !== []) {
            $row = $this->sync($row, $dirty);
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
     * One-time backfill entry point (see
     * {@see \Vatly\FluentCart\Support\SubscriptionBillingBackfill}): re-fetch
     * the Vatly subscription for an already-linked FluentCart row and fill in
     * billing-schedule columns an older build of store() left null. Reuses the
     * same fetch + mapping + state sync as store(), so the date mapping
     * lives in one place. Returns true when the row was updated, false when
     * there's no Vatly id to resolve or Vatly reports no dates yet.
     */
    public function backfillBillingSchedule(Subscription $row): bool
    {
        $vatlyId = (string) ($row->vendor_subscription_id ?? '');
        if ($vatlyId === '') {
            return false;
        }

        $schedule = $this->billingScheduleFrom($this->fetchVatlySubscription($vatlyId));
        if ($schedule === []) {
            return false;
        }

        $this->sync($row, $schedule);

        return true;
    }

    /**
     * Write through FluentCart's own state sync so its subscription hooks fire.
     *
     * @param array<string, mixed> $args
     */
    private function sync(Subscription $row, array $args): Subscription
    {
        return SubscriptionService::syncSubscriptionStates($row, $args) ?? $row;
    }

    /**
     * Fetch the Vatly subscription resource. store() needs it both to read the
     * `fluentcart_subscription_id` we stamped in metadata and to back-fill the
     * billing schedule onto the FluentCart row.
     */
    private function fetchVatlySubscription(string $vatlySubscriptionId, ?bool $testmode = null): ?object
    {
        try {
            $vatly = $testmode === null ? $this->plugin->vatly() : $this->plugin->vatlyForTestmode($testmode);

            return $vatly->getSubscription()->execute($vatlySubscriptionId);
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
     * datetime column format.
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
            return (new DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
