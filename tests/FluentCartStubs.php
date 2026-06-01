<?php
/**
 * Stubs for FluentCart + this plugin's own constants.
 *
 * Loaded by BOTH PHPStan analysis (via `phpstan.neon` bootstrapFiles) and
 * PHPUnit unit tests (via `tests/bootstrap.php`). FluentCart has no public
 * stub package today, so this file is the single source for the SUT's
 * compile-time and unit-test load-time view of those classes.
 *
 * WordPress core symbols (ABSPATH, dbDelta, *_IN_SECONDS, WP_Error, …) are
 * supplied by szepeviktor/phpstan-wordpress for PHPStan and by
 * `tests/WPStubs.php` for PHPUnit, so they don't appear here.
 *
 * Wrapped entirely in `namespace { }` blocks because PHP forbids mixing
 * bracketed-namespace code with unbracketed top-level statements.
 *
 * NOTE: when integration tests load with the real FluentCart plugin
 * installed, this file MUST NOT be required — the namespaced classes below
 * would clash with the real ones. The integration bootstrap (added in PR C)
 * omits this require.
 */

namespace {
    defined('VATLY_FLUENTCART_VERSION')      || define('VATLY_FLUENTCART_VERSION', '0.0.0');
    defined('VATLY_FLUENTCART_FILE')         || define('VATLY_FLUENTCART_FILE', '');
    defined('VATLY_FLUENTCART_DIR')          || define('VATLY_FLUENTCART_DIR', '');
    defined('VATLY_FLUENTCART_URL')          || define('VATLY_FLUENTCART_URL', '');
    defined('VATLY_FLUENTCART_GATEWAY_SLUG') || define('VATLY_FLUENTCART_GATEWAY_SLUG', 'vatly');

    if (! function_exists('fluent_cart_api')) {
        function fluent_cart_api(): object { return new \stdClass(); }
    }

    // Belt-and-braces safety: if the real FluentCart plugin is already loaded
    // (e.g. a future integration bootstrap forgot to skip this file), bail
    // out before redeclaring any of the namespaced classes below. The header
    // comment is documentation; this is enforcement.
    if (class_exists('FluentCart\\App\\Models\\Order', false)) {
        return;
    }
}

namespace FluentCart\App {

    /**
     * Minimal query-builder stub. Returns chainable self everywhere and lets
     * find()/first() return the model type the caller is querying — so
     * Order::query()->find($id) is typed as ?Order, not stdClass.
     *
     * @template TModel of object
     */
    class Builder
    {
        /** @return static<TModel> */
        public function where(string $column, mixed $value = null, mixed $extra = null): self { return $this; }

        /** @param array<int|string, mixed> $values
         *  @return static<TModel> */
        public function whereIn(string $column, array $values): self { return $this; }

        /** @return static<TModel> */
        public function orWhere(string $column, mixed $value = null): self { return $this; }

        /** @return static<TModel> */
        public function orWhereHas(string $column, \Closure $callback): self { return $this; }

        /** @return static<TModel> */
        public function orderByDesc(string $column): self { return $this; }

        /** @return TModel|null */
        public function first(): ?object { return null; }

        /** @return TModel|null */
        public function find(int|string $id): ?object { return null; }
    }
}

namespace FluentCart\App\Modules\PaymentMethods\Core {

    abstract class AbstractPaymentGateway
    {
        /** @var array<int, string> */
        public array $supportedFeatures = [];

        public function __construct(mixed ...$args) {}

        /** @return array<string, mixed> */
        abstract public function meta(): array;

        abstract public function has(string $feature): bool;
    }

    abstract class AbstractPaymentSettings
    {
        public string $optionKey = '';

        /** @return array<string, array<string, mixed>> */
        abstract public function fields(): array;
    }

    /**
     * Surface inferred from the FluentCart dev-docs Paddle case study.
     */
    final class PaymentInstance
    {
        public object $order;
        public object $transaction;
        public ?object $subscription = null;
    }
}

namespace FluentCart\App\Models {

    use FluentCart\App\Builder;

    class Order
    {
        public int $id;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        public function updateMeta(string $key, string $value): void {}
    }

    class OrderTransaction
    {
        public int $id;
        // FluentCart populates uuid on most transactions but the public docs
        // don't guarantee it; defensive nullable so our `?? $id` fallbacks
        // type-check correctly.
        public ?string $uuid = null;
        public ?string $vendor_charge_id = null;
        public ?string $status = null;
        public ?int $total = null;
        public ?string $currency = null;
        public ?string $invoice_number = null;
        public ?string $payment_method = null;
        public ?string $payment_mode = null;
        public ?int $subscription_id = null;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        /** @param array<string, mixed> $attrs */
        public function fill(array $attrs): self { return $this; }

        public function save(): bool { return true; }

        public function refresh(): self { return $this; }
    }

    class Refund
    {
        public int $id;
        public ?string $vendor_charge_id = null;
        public ?string $status = null;
        public ?int $total = null;
        public ?string $currency = null;
        public ?string $payment_method = null;
        public ?string $payment_mode = null;
        public ?int $parent_transaction_id = null;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        /** @param array<string, mixed> $attrs */
        public function fill(array $attrs): self { return $this; }

        public function save(): bool { return true; }
    }

    class Subscription
    {
        public int $id;
        public ?int $customer_id = null;
        public ?string $vendor_subscription_id = null;
        public ?string $vendor_customer_id = null;
        public ?string $vendor_plan_id = null;
        public ?string $payment_method = null;
        public ?string $status = null;
        public ?int $quantity = null;
        public ?string $subscription_type = null;
        public ?string $item_name = null;
        public ?string $name = null;
        public ?string $canceled_at = null;
        public ?string $expire_at = null;
        public ?string $next_billing_date = null;
        public ?object $customer = null;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        /** @param array<string, mixed> $attrs */
        public function fill(array $attrs): self { return $this; }

        public function save(): bool { return true; }

        public function refresh(): self { return $this; }
    }
}

namespace FluentCart\App\Services\Payments {

    use FluentCart\App\Models\OrderTransaction;

    class Confirmations
    {
        /** @param array<string, mixed> $details */
        public function confirmPaymentSuccessByCharge(OrderTransaction $transaction, array $details): void {}
    }

    class Refund
    {
        /** @param array<string, mixed> $args */
        public static function createOrRecordRefund(array $args, OrderTransaction $parent): void {}
    }
}

namespace FluentCart\App\Services\Subscription {

    use FluentCart\App\Models\Subscription;

    class SubscriptionRenewal
    {
        /** @param array<string, mixed> $args */
        public static function recordRenewalPayment(Subscription $subscription, array $args): void {}
    }
}
