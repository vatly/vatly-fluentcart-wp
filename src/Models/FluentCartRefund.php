<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Models;

use FluentCart\App\Models\Refund;
use Vatly\Fluent\Contracts\RefundInterface;

/**
 * {@see RefundInterface} adapter wrapping a FluentCart Refund row.
 *
 * Returned from {@see \Vatly\FluentCart\Repositories\FluentCartRefundRepository}
 * so fluent's built-in {@see \Vatly\Fluent\Webhooks\Reactions\SyncRefundOnStatusChange}
 * can update us through the typed contract without exposing the underlying
 * Eloquent model.
 *
 * The original Vatly order id isn't stored on the FluentCart Refund row in
 * vendor-neutral terms — `parent_transaction_id` points to a FluentCart
 * OrderTransaction, whose `vendor_charge_id` is the Vatly order id. Recovering
 * it would require a lookup we don't want to do on every getter call; for now
 * `getOriginalOrderId()` returns the empty string because the built-in
 * reaction doesn't actually consume it post-store.
 */
final class FluentCartRefund implements RefundInterface
{
    public function __construct(public readonly Refund $refund) {}

    public function getVatlyId(): string
    {
        return (string) ($this->refund->vendor_charge_id ?? '');
    }

    public function getStatus(): string
    {
        return (string) ($this->refund->status ?? '');
    }

    public function getTotal(): int
    {
        return (int) ($this->refund->total ?? 0);
    }

    public function getCurrency(): string
    {
        return (string) ($this->refund->currency ?? '');
    }

    public function getOriginalOrderId(): string
    {
        return '';
    }

    public function isCompleted(): bool
    {
        return ($this->refund->status ?? null) === 'refunded';
    }

    /**
     * Test vs live, read from FluentCart's per-refund `payment_mode` (carried
     * over from the parent transaction at refund-creation time — see
     * {@see \Vatly\FluentCart\PaymentMethod\RefundService}).
     */
    public function isTestmode(): bool
    {
        return ($this->refund->payment_mode ?? null) === 'test';
    }
}
