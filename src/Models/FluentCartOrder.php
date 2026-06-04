<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Models;

use FluentCart\App\Models\OrderTransaction;
use Vatly\Fluent\Contracts\OrderInterface;

/**
 * Thin {@see OrderInterface} adapter wrapping a FluentCart OrderTransaction.
 *
 * Returned from {@see \Vatly\FluentCart\Repositories\FluentCartOrderRepository}
 * so vatly-fluent-php's built-in reactions can pass our domain rows through
 * their `OrderInterface` typed parameters without exposing the underlying
 * Eloquent model to fluent's contracts.
 */
final class FluentCartOrder implements OrderInterface
{
    public function __construct(public readonly OrderTransaction $transaction) {}

    public function getVatlyId(): string
    {
        return (string) ($this->transaction->vendor_charge_id ?? '');
    }

    public function getStatus(): string
    {
        return (string) ($this->transaction->status ?? '');
    }

    public function getInvoiceNumber(): ?string
    {
        $invoice = $this->transaction->invoice_number ?? null;
        return $invoice !== null && $invoice !== '' ? (string) $invoice : null;
    }

    public function getTotal(): int
    {
        return (int) ($this->transaction->total ?? 0);
    }

    public function getCurrency(): string
    {
        return (string) ($this->transaction->currency ?? '');
    }

    public function getPaymentMethod(): ?string
    {
        $pm = $this->transaction->payment_method ?? null;
        return $pm !== null && $pm !== '' ? (string) $pm : null;
    }

    public function isPaid(): bool
    {
        return ($this->transaction->status ?? null) === 'succeeded';
    }
}
