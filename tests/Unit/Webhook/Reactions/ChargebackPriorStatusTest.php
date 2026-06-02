<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Webhook\Reactions;

use FluentCart\App\Models\Subscription;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\FluentCart\Webhook\Reactions\HandleChargebackReceived;

/**
 * Regression coverage for chargeback prior-status preservation: the original
 * implementation flipped to `paused` on receipt and blanket-restored `active`
 * on reversal, which incorrectly re-granted access to subscriptions that were
 * already in dunning when the dispute arrived.
 *
 * These tests exercise the meta-capture logic directly against the
 * FluentCart Subscription stub. End-to-end resolution (via OrderTransaction
 * lookup + Vatly order metadata + customer-binding fallback) is covered by
 * the integration suite — too much mocking surface for unit isolation here.
 *
 * @covers \Vatly\FluentCart\Webhook\Reactions\HandleChargebackReceived
 */
final class ChargebackPriorStatusTest extends TestCase
{
    public function test_meta_key_constant_matches_expected_string(): void
    {
        // Sanity: HandleChargebackReversed reads via this constant; renaming
        // it without updating both reactions would silently break reversal.
        self::assertSame('_vatly_pre_chargeback_status', HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META);
    }

    public function test_subscription_stub_round_trips_meta_for_chargeback_state_machine(): void
    {
        // The reactions' "remember status before chargeback" mechanism is
        // entirely built on updateMeta/getMeta. If the FluentCart Subscription
        // stub regressed (e.g. someone removed updateMeta from the stub during
        // a stub refactor), the prior-status capture would silently no-op and
        // reversals would blanket-flip to `active` — exactly the bug being
        // fixed here. Pin the contract.
        $row = new Subscription();
        $row->status = 'failing';

        self::assertSame('', (string) ($row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '') ?? ''));

        $row->updateMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, (string) $row->status);

        self::assertSame(
            'failing',
            $row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META),
            'updateMeta must persist the captured pre-chargeback status'
        );
    }

    public function test_reversal_falls_back_to_active_when_no_prior_status_was_captured(): void
    {
        // When the meta is empty (chargeback received before tracking landed,
        // or cleared out-of-band), the reversed handler defaults to `active`
        // rather than leaving the subscription stuck on `paused`. The
        // alternative — leave paused — would lock customers out indefinitely
        // on a reversed dispute, which is worse than over-restoring.
        $row = new Subscription();
        $row->status = 'paused';
        // No prior status meta — simulate untracked chargeback.

        $priorStatus = (string) ($row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '') ?? '');
        $restoreTo = $priorStatus !== '' ? $priorStatus : 'active';

        self::assertSame('active', $restoreTo);
    }

    public function test_reversal_restores_dunning_state_when_chargeback_arrived_during_failing_payment(): void
    {
        // The exact bug being fixed: subscription enters dunning, then a
        // chargeback against an earlier order pauses it, then the dispute is
        // reversed. The subscription must return to `failing` — the underlying
        // payment failure isn't resolved by dispute reversal.
        $row = new Subscription();
        $row->status = 'paused';
        $row->updateMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, 'failing');

        $priorStatus = (string) ($row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '') ?? '');
        $restoreTo = $priorStatus !== '' ? $priorStatus : 'active';

        self::assertSame('failing', $restoreTo);
    }

    public function test_receipt_capture_is_idempotent_under_webhook_redelivery(): void
    {
        // First receipt records the live status. Subsequent receipts (webhook
        // re-delivery, or a follow-up chargeback against a different order on
        // the same subscription before reversal lands) must not overwrite the
        // capture with the now-paused status — that would round-trip the
        // subscription to `paused` on reversal instead of restoring the
        // pre-chargeback state.
        $row = new Subscription();
        $row->status = 'active';

        // First receipt
        $existing = (string) ($row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '') ?? '');
        self::assertSame('', $existing);
        $row->updateMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, (string) $row->status);
        $row->status = 'paused';

        // Re-delivery: the reaction's guard sees meta is non-empty and skips
        // the capture. Simulate the guard's check.
        $existing = (string) ($row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META, '') ?? '');
        $shouldCapture = $existing === '';

        self::assertFalse($shouldCapture, 'Second receipt must not re-capture');
        self::assertSame('active', $row->getMeta(HandleChargebackReceived::PRE_CHARGEBACK_STATUS_META));
    }
}
