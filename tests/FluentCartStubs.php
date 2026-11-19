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

    if (! function_exists('fluent_cart_get_option')) {
        /**
         * phpunit-only: options staged in $GLOBALS['fluent_cart_options'].
         *
         * @return mixed
         */
        function fluent_cart_get_option(string $metaKey, mixed $default = false, bool $cache = true)
        {
            return $GLOBALS['fluent_cart_options'][$metaKey] ?? $default;
        }
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
        /**
         * phpunit-only test seam: rows staged here are returned by the next
         * first()/find() call(s), FIFO, so feature tests can drive code that
         * resolves models via `Model::query()->...->first()` without a real
         * database. PHPStan never reaches this branch (the queue starts empty),
         * so analysis still sees the conservative `null` return below.
         *
         * @var array<int, object>
         */
        public static array $nextResults = [];

        /**
         * phpunit-only test seam: each array staged here is returned by the
         * next get() call, FIFO, so tests can drive code that resolves a batch
         * of rows via `Model::query()->...->get()` without a real database.
         * PHPStan never reaches this branch (the queue starts empty), so
         * analysis still sees the conservative empty-array return below.
         *
         * @var array<int, array<int, object>>
         */
        public static array $nextCollections = [];

        /**
         * phpunit-only test seam: every where/orWhere/whereIn hop is appended
         * here, so a test can assert *how* a query was built — e.g. that a hex
         * uuid is matched on the `uuid` column and never coerced onto the
         * integer `id` column. PHPStan never inspects this.
         *
         * @var array<int, array{method: string, column: string, value: mixed}>
         */
        public static array $recordedCalls = [];

        /**
         * phpunit-only test seam: every `where($column, $value)` call, in
         * order, in a simpler [column, value] shape. Kept alongside
         * $recordedCalls because the subscription-attribution tests assert on
         * this log while the query-shape tests assert on $recordedCalls.
         *
         * @var array<int, array{0: string, 1: mixed}>
         */
        public static array $wheres = [];

        /** Reset the staged-result queues + recorded calls (call in test setUp/tearDown). */
        public static function reset(): void
        {
            self::$nextResults = [];
            self::$nextCollections = [];
            self::$recordedCalls = [];
            self::$wheres = [];
        }

        /** @return static<TModel> */
        public function where(string $column, mixed $value = null, mixed $extra = null): self
        {
            self::$recordedCalls[] = ['method' => 'where', 'column' => $column, 'value' => $value];
            self::$wheres[] = [$column, $value];
            return $this;
        }

        /** @param array<int|string, mixed> $values
         *  @return static<TModel> */
        public function whereIn(string $column, array $values): self
        {
            self::$recordedCalls[] = ['method' => 'whereIn', 'column' => $column, 'value' => $values];
            return $this;
        }

        /** @return static<TModel> */
        public function orWhere(string $column, mixed $value = null): self
        {
            self::$recordedCalls[] = ['method' => 'orWhere', 'column' => $column, 'value' => $value];
            return $this;
        }

        /** @return static<TModel> */
        public function orWhereHas(string $column, \Closure $callback): self { return $this; }

        /** @return static<TModel> */
        public function orderByDesc(string $column): self { return $this; }

        /** @return static<TModel> */
        public function whereNull(string $column): self
        {
            self::$recordedCalls[] = ['method' => 'whereNull', 'column' => $column, 'value' => null];
            return $this;
        }

        /** @return static<TModel> */
        public function whereNotNull(string $column): self
        {
            self::$recordedCalls[] = ['method' => 'whereNotNull', 'column' => $column, 'value' => null];
            return $this;
        }

        /** @return static<TModel> */
        public function orderBy(string $column, string $direction = 'asc'): self
        {
            self::$recordedCalls[] = ['method' => 'orderBy', 'column' => $column, 'value' => $direction];
            return $this;
        }

        /** @return static<TModel> */
        public function limit(int $count): self
        {
            self::$recordedCalls[] = ['method' => 'limit', 'column' => '', 'value' => $count];
            return $this;
        }

        /** @return TModel|null */
        public function first(): ?object { return $this->dequeue(); }

        /** @return TModel|null */
        public function find(int|string $id): ?object { return $this->dequeue(); }

        /**
         * Returns the next staged collection (FIFO), or an empty array.
         *
         * @return array<int, object>
         */
        public function get(): array
        {
            if (self::$nextCollections !== []) {
                return array_shift(self::$nextCollections);
            }

            return [];
        }

        /** @return TModel|null */
        private function dequeue(): ?object
        {
            if (self::$nextResults !== []) {
                /** @var TModel $row */
                $row = array_shift(self::$nextResults);
                return $row;
            }

            return null;
        }
    }
}

namespace FluentCart\App\Modules\PaymentMethods\Core {

    /**
     * Mirrors FluentCart 2.2.0's gateway contract closely enough to catch
     * signature drift in this plugin's own gateway:
     *
     *  - `getOrderInfo(array $data)` is a real interface method; omitting it in
     *    the SUT must fail to load (as it did when the plugin targeted an older
     *    FluentCart that had no such method).
     *  - `processRefund($transaction, $amount, $args)` is UNTYPED here, exactly
     *    as FluentCart declares it, so re-adding parameter type hints in the SUT
     *    override is an incompatible signature and fails at load.
     */
    abstract class AbstractPaymentGateway
    {
        /** @var array<int, string> */
        public array $supportedFeatures = [];

        /** @var BaseGatewaySettings */
        public $settings;

        /** @var AbstractSubscriptionModule|null */
        public $subscriptions;

        /**
         * Same signature as FluentCart: passing a subscription module adds the
         * `subscriptions` feature.
         */
        public function __construct(BaseGatewaySettings $settings, ?AbstractSubscriptionModule $subscriptions = null)
        {
            $this->settings = $settings;
            if ($subscriptions) {
                $this->supportedFeatures[] = 'subscriptions';
            }
            $this->subscriptions = $subscriptions;
        }

        /** @return array<string, mixed> */
        abstract public function meta(): array;

        abstract public function has(string $feature): bool;

        abstract public function getOrderInfo(array $data);

        public function processRefund($transaction, $amount, $args)
        {
            return new \WP_Error('not_implemented', 'Refunds not implemented.');
        }

        public function isEnabled(): bool
        {
            return false;
        }

        public function renderStoreModeNotice(): string
        {
            return '';
        }
    }

    /**
     * FluentCart 2.2.0's settings base: a fct_meta-backed store whose subclasses
     * implement the three accessors. (The real constructor loads from the Meta
     * table; the stub ctor is a no-op so settings classes instantiate without a
     * database.)
     */
    /**
     * FluentCart's settings base: loads the saved settings for $methodHandler
     * from FluentCart's option store, over the subclass defaults.
     */
    abstract class BaseGatewaySettings
    {
        /** @var array<string, mixed> */
        public $settings;

        /** @var string */
        public $methodHandler;

        public function __construct()
        {
            $saved = fluent_cart_get_option((string) $this->methodHandler, []);
            $this->settings = array_merge(static::getDefaults(), is_array($saved) ? $saved : []);
        }

        /** @return array<string, mixed> */
        public static function getDefaults() { return []; }

        /** @return mixed */
        abstract public function get($key = '');

        /** @return mixed */
        abstract public function getMode();

        /** @return mixed */
        abstract public function isActive();
    }

    abstract class AbstractSubscriptionModule
    {
        /** @return mixed */
        public function cancel($vendorSubscriptionId, $args = [])
        {
            return new \WP_Error('not_implemented', 'Cancel subscription is not implemented for this payment method.');
        }
    }

    /**
     * FluentCart 2.2.0 moved PaymentInstance to App\Services\Payments; see that
     * namespace below. (Kept nothing here on purpose.)
     */
}

namespace FluentCart\App\Models {

    use FluentCart\App\Builder;

    class Order
    {
        public int $id;
        public ?string $payment_method = null;
        /** @var array<int, object> FluentCart's line items relation (fct_order_items) */
        public array $order_items = [];
        /** @var array<string, mixed> in-memory mirror of FluentCart's order meta — phpunit-only */
        public array $meta = [];
        public int $total_amount = 0;
        public int $total_paid = 0;
        public ?string $payment_status = null;
        /** @var array<int, array{0: string, 1: string, 2: string}> phpunit-only: addLog() calls */
        public array $logs = [];

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        public function save(): bool { return true; }

        public function addLog($title, $description = '', $type = 'info', $by = ''): void { $this->logs[] = [(string) $title, (string) $description, (string) $type]; }

        public function updateMeta(string $key, string $value): void { $this->meta[$key] = $value; }

        public function getMeta(string $key, mixed $default = null): mixed { return $this->meta[$key] ?? $default; }
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
        // FluentCart 2.2.0 models a refund as an OrderTransaction row with
        // transaction_type = 'refund' (linked to its parent via meta.parent_id),
        // so the refund wrappers/repositories query this type.
        public ?string $transaction_type = null;
        /** @var array<string, mixed> in-memory mirror of the transaction's JSON meta column */
        public array $meta = [];

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        /** @param array<string, mixed> $attrs */
        public function fill(array $attrs): self
        {
            foreach ($attrs as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }

            return $this;
        }

        public function save(): bool { return true; }

        public function refresh(): self { return $this; }

        /** @return object relation accessor used by the gateway's receipt-URL/order lookups */
        public ?object $order = null;
    }

    class Subscription
    {
        public int $id;
        /** @var array<string, mixed> in-memory mirror of FluentCart's subscription meta — phpunit-only */
        public array $meta = [];
        public ?int $customer_id = null;
        public ?string $vendor_subscription_id = null;
        public ?string $vendor_customer_id = null;
        public ?string $vendor_plan_id = null;
        // FluentCart 2.2.0 stores the gateway on `current_payment_method`
        // (there is no `payment_method` column on subscriptions).
        public ?string $current_payment_method = null;
        public ?string $payment_mode = null;
        public ?string $status = null;
        public ?int $quantity = null;
        public ?string $subscription_type = null;
        public ?string $item_name = null;
        public ?string $name = null;
        public ?string $canceled_at = null;
        public ?string $expire_at = null;
        public ?string $next_billing_date = null;
        public ?int $trial_days = null;
        public ?string $trial_ends_at = null;
        public ?object $customer = null;
        public ?object $order = null;

        /** @return Builder<self> */
        public static function query(): Builder { return new Builder(); }

        public function guessNextBillingDate($forced = false): string { return '2099-01-01 00:00:00'; }

        /**
         * Eloquent-style mass assignment. The stub applies the attributes to
         * the matching public properties so phpunit tests can observe a
         * `fill(['status' => 'past_due'])->save()` flip on the in-memory row.
         *
         * @param array<string, mixed> $attrs
         */
        public function fill(array $attrs): self
        {
            foreach ($attrs as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }

            return $this;
        }

        public function save(): bool { return true; }

        public function refresh(): self { return $this; }

        public function updateMeta(string $key, string $value): void { $this->meta[$key] = $value; }

        public function getMeta(string $key, mixed $default = null): mixed { return $this->meta[$key] ?? $default; }
    }
}

namespace FluentCart\App\Services\Payments {

    use FluentCart\App\Models\OrderTransaction;

    /**
     * FluentCart 2.2.0 moved PaymentInstance here from
     * App\Modules\PaymentMethods\Core. It is the object handed to a gateway's
     * makePaymentFromPaymentInstance().
     */
    final class PaymentInstance
    {
        public object $order;
        public object $transaction;
        public ?object $subscription = null;
        public ?string $paymentType = null;
    }

    class Refund
    {
        /**
         * phpunit-only test seam: the args of the most recent
         * createOrRecordRefund() call, so feature tests can assert the recorded
         * refund payload (status/total/…) without a real database. PHPStan
         * never inspects this; it only sees the no-op below.
         *
         * @var array{0: array<string, mixed>, 1: OrderTransaction}|null
         */
        public static ?array $lastRecorded = null;

        /**
         * Mirrors FluentCart's return contract (the created/existing refund
         * OrderTransaction, or a WP_Error on an invalid amount) so the refund
         * repository's store() success path is exercisable. Records the args for
         * assertion and hands back a refund row carrying them.
         *
         * @param array<string, mixed> $args
         */
        public static function createOrRecordRefund(array $args, OrderTransaction $parent): OrderTransaction|\WP_Error
        {
            self::$lastRecorded = [$args, $parent];

            $refund                   = new OrderTransaction();
            $refund->transaction_type = 'refund';
            $refund->vendor_charge_id = isset($args['vendor_charge_id']) ? (string) $args['vendor_charge_id'] : null;
            $refund->status           = isset($args['status']) ? (string) $args['status'] : null;
            $refund->total            = isset($args['total']) ? (int) $args['total'] : null;

            return $refund;
        }
    }
}

namespace FluentCart\App\Helpers {

    /** The subset of FluentCart 2.2.0's status enum the gateway relies on. */
    class Status
    {
        public const TRANSACTION_SUCCEEDED   = 'succeeded';
        public const TRANSACTION_PENDING     = 'pending';
        public const TRANSACTION_REFUNDED    = 'refunded';
        public const TRANSACTION_FAILED      = 'failed';
        public const TRANSACTION_TYPE_CHARGE = 'charge';
        public const TRANSACTION_TYPE_REFUND = 'refund';
    }

    /**
     * FluentCart 2.2.0's canonical post-payment step: claims the order's
     * pending -> paid transition after a charge transaction is marked
     * succeeded. The gateway uses it in place of the (Stripe-specific)
     * Confirmations service the plugin targeted on older FluentCart.
     */
    /**
     * phpunit-only stand-in for FluentCart's openssl-backed key helpers: a
     * reversible wrapper, so tests can tell a stored value was encrypted.
     */
    class Helper
    {
        public static function encryptKey($value)
        {
            return ! $value || self::isValueEncrypted($value) ? $value : 'enc:' . base64_encode((string) $value);
        }

        public static function decryptKey($rawValue)
        {
            return self::isValueEncrypted($rawValue) ? base64_decode(substr((string) $rawValue, 4)) : $rawValue;
        }

        public static function isValueEncrypted($rawValue): bool
        {
            return is_string($rawValue) && str_starts_with($rawValue, 'enc:');
        }
    }

    class StatusHelper
    {
        public function __construct(mixed $order = null) {}

        public function syncOrderStatuses(mixed $latestTransaction = null): mixed { return null; }
    }
}

namespace FluentCart\App\Modules\Subscriptions\Services {

    use FluentCart\App\Models\Subscription;

    /**
     * FluentCart 2.2.0 relocated renewal recording here (from
     * App\Services\Subscription\SubscriptionRenewal) and reordered the args:
     * the transaction-data array is first, the subscription second.
     */
    class SubscriptionService
    {
        /**
         * phpunit-only: the last recorded renewal, as
         * [$subscriptionModel, $transactionData, $subscriptionUpdateArgs].
         *
         * @var array{0: ?Subscription, 1: array<string, mixed>, 2: array<string, mixed>}|null
         */
        public static ?array $lastRecorded = null;

        /**
         * @param array<string, mixed> $transactionData
         * @param array<string, mixed> $subscriptionUpdateArgs
         * @return mixed
         */
        public static function recordRenewalPayment(array $transactionData, ?Subscription $subscriptionModel = null, array $subscriptionUpdateArgs = [])
        {
            self::$lastRecorded = [$subscriptionModel, $transactionData, $subscriptionUpdateArgs];

            if ($subscriptionModel !== null) {
                self::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateArgs);
            }

            return null;
        }

        /**
         * phpunit-only: every sync, as [$subscriptionModel, $args, $oldStatus].
         *
         * @var array<int, array{0: Subscription, 1: array<string, mixed>, 2: ?string}>
         */
        public static array $synced = [];

        /**
         * Applies the args like FluentCart does (fill + save) and records the call
         * so tests can see that a status change went through FluentCart.
         *
         * @param array<string, mixed> $subscriptionUpdateArgs
         */
        public static function syncSubscriptionStates(Subscription $subscriptionModel, $subscriptionUpdateArgs = [], $expectedStatus = null)
        {
            self::$synced[] = [$subscriptionModel, $subscriptionUpdateArgs, $subscriptionModel->status];
            $subscriptionModel->fill($subscriptionUpdateArgs)->save();

            return $subscriptionModel;
        }

        public static function reset(): void
        {
            self::$lastRecorded = null;
            self::$synced = [];
        }
    }
}

namespace FluentCart\App\Events\Subscription {

    class SubscriptionActivated
    {
        /** @var array<int, self> phpunit-only */
        public static array $dispatched = [];

        public function __construct(public mixed $subscription, public mixed $order = null, public mixed $customer = null) {}

        public function dispatch(): void { self::$dispatched[] = $this; }
    }
}

namespace FluentCart\Api {

    class StoreSettings
    {
        /** @var string phpunit-only: the store's order mode */
        public static string $orderMode = 'test';

        /**
         * @param string|null $key
         * @param mixed       $default
         * @return mixed
         */
        public function get($key = null, $default = null)
        {
            return $key === 'order_mode' ? self::$orderMode : $default;
        }
    }
}

namespace FluentCart\App\Events\Order {

    class OrderPaymentFailed
    {
        /** @var array<int, self> phpunit-only */
        public static array $dispatched = [];

        public function __construct(public mixed $order, public mixed $transaction = null, public mixed $oldStatus = null, public mixed $newStatus = null, public mixed $reason = null) {}

        public function dispatch(): void { self::$dispatched[] = $this; }
    }

    class OrderStatusUpdated
    {
        /** @var array<int, self> phpunit-only */
        public static array $dispatched = [];

        /** @param array<string, mixed> $activity */
        public function __construct(public mixed $order, public mixed $oldStatus = null, public mixed $newStatus = null, public mixed $manageStock = true, public array $activity = [], public string $type = 'payment_status') {}

        public function dispatch(): void { self::$dispatched[] = $this; }
    }
}
