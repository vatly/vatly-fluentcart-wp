<?php
/**
 * Stubs used only by PHPStan analysis. Never loaded at runtime.
 *
 * Wrapped entirely in `namespace { }` blocks because PHP forbids mixing
 * bracketed-namespace code with unbracketed top-level statements.
 */

namespace {
    defined('VATLY_FLUENTCART_VERSION')      || define('VATLY_FLUENTCART_VERSION', '0.0.0');
    defined('VATLY_FLUENTCART_FILE')         || define('VATLY_FLUENTCART_FILE', '');
    defined('VATLY_FLUENTCART_DIR')          || define('VATLY_FLUENTCART_DIR', '');
    defined('VATLY_FLUENTCART_URL')          || define('VATLY_FLUENTCART_URL', '');
    defined('VATLY_FLUENTCART_GATEWAY_SLUG') || define('VATLY_FLUENTCART_GATEWAY_SLUG', 'vatly');

    // Pointed at a real-ish path so require_once ABSPATH . '...' resolves;
    // the file doesn't actually need to exist because PHPStan doesn't run the
    // require, it just checks the literal path looks file-shaped.
    defined('ABSPATH')             || define('ABSPATH', '/var/www/html/');
    defined('DAY_IN_SECONDS')      || define('DAY_IN_SECONDS', 86400);
    defined('MINUTE_IN_SECONDS')   || define('MINUTE_IN_SECONDS', 60);
    defined('HOUR_IN_SECONDS')     || define('HOUR_IN_SECONDS', 3600);
    defined('WEEK_IN_SECONDS')     || define('WEEK_IN_SECONDS', 604800);

    if (! function_exists('fluent_cart_api')) {
        function fluent_cart_api(): object { return new \stdClass(); }
    }

    if (! function_exists('dbDelta')) {
        /** @param string|string[] $queries */
        function dbDelta($queries): array { return []; }
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
        public string $uuid = '';
        public ?string $vendor_charge_id = null;
        public ?string $status = null;
        public ?int $total = null;
        public ?string $currency = null;
        public ?string $invoice_number = null;
        public ?string $payment_method = null;
        public ?string $payment_mode = null;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        /** @param array<string, mixed> $attrs */
        public function fill(array $attrs): self { return $this; }

        public function save(): bool { return true; }

        public function refresh(): self { return $this; }
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
        public ?string $ended_at = null;
        public ?object $customer = null;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        /** @param array<string, mixed> $attrs */
        public function fill(array $attrs): self { return $this; }

        public function save(): bool { return true; }
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
