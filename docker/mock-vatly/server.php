<?php
/**
 * Minimal mock of the Vatly REST API for local development.
 *
 * Speaks v1 of the API — the version vatly/vatly-api-php targets — and
 * implements just enough surface to exercise the gateway end-to-end:
 *
 *   POST /v1/customers                       → create customer
 *   GET  /v1/customers/{id}                  → fetch customer
 *   POST /v1/checkouts                       → create checkout (returns href)
 *   GET  /v1/checkouts/{id}                  → fetch checkout
 *   GET  /v1/subscriptions/{id}              → fetch subscription
 *   DELETE /v1/subscriptions/{id}            → cancel subscription
 *   GET  /v1/orders/{id}                     → fetch order (with lines[]; ?lines=N)
 *   POST /v1/orders/{orderId}/refunds/full   → full refund
 *   POST /v1/orders/{orderId}/refunds        → item-level (partial) refund
 *
 *   POST /__webhook/{event}                  → dev helper: signs & POSTs a webhook
 *   GET  /__hosted/{checkout_id}             → browser hosted-checkout simulator
 *
 * State lives in /tmp/mock-vatly-state.json (override with the
 * MOCK_VATLY_STATE_FILE env var). Safe to nuke; the next call recreates
 * anything it needs.
 */

declare(strict_types=1);

final class MockVatly
{

    private const STATE_FILE_DEFAULT = '/tmp/mock-vatly-state.json';
    private const WEBHOOK_SECRET     = 'mock-secret-for-dev-only-do-not-use-in-prod';
    private const HOSTED_BASE        = 'http://localhost:8081/__hosted';

    /**
     * Where state is persisted. Overridable via `MOCK_VATLY_STATE_FILE` so
     * tests (which run their own `php -S` instance) can point at an isolated,
     * inspectable file instead of the shared `/tmp` default.
     */
    private function stateFile(): string
    {
        return getenv('MOCK_VATLY_STATE_FILE') ?: self::STATE_FILE_DEFAULT;
    }

    public function handle(string $method, string $path): void
    {
        header('Content-Type: application/json');

        try {
            $response = $this->route($method, $path);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);

            return;
        }

        if ($response === null) {
            // Routes like hosted_page() write their own response.
            return;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function route(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        if ($method === 'POST' && $path === '/v1/customers') {
            return $this->create_customer();
        }

        if ($method === 'POST' && $path === '/v1/checkouts') {
            return $this->create_checkout();
        }

        if ($method === 'GET' && preg_match('#^/v1/customers/(.+)$#', $path, $m)) {
            return $this->get_customer($m[1]);
        }

        if ($method === 'GET' && preg_match('#^/v1/checkouts/(.+)$#', $path, $m)) {
            return $this->get_checkout($m[1]);
        }

        if ($method === 'GET' && preg_match('#^/v1/subscriptions/(.+)$#', $path, $m)) {
            return $this->get_subscription($m[1]);
        }

        if ($method === 'DELETE' && preg_match('#^/v1/subscriptions/(.+)$#', $path, $m)) {
            return $this->cancel_subscription($m[1]);
        }

        if ($method === 'GET' && preg_match('#^/v1/orders/(.+)$#', $path, $m)) {
            return $this->get_order($m[1]);
        }

        // SDK call: `createFullRefundForOrderId($orderId, ...)` → sets
        // parentId=$orderId, resourcePath="orders_refunds/full" → BaseEndpoint
        // splits on the first underscore: `orders/{orderId}/refunds/full`.
        //
        // NB: register the more specific `/refunds/full` route BEFORE the
        // generic `/refunds` route below, or `/full` would match the generic
        // item-level handler instead.
        if ($method === 'POST' && preg_match('#^/v1/orders/([^/]+)/refunds/full$#', $path, $m)) {
            return $this->full_refund($m[1]);
        }

        // SDK call: `createForOrderId($orderId, ['items' => [...]])` → sets
        // parentId=$orderId, resourcePath="orders_refunds" → BaseEndpoint
        // splits: `orders/{orderId}/refunds`. This is the item-level (partial)
        // refund endpoint.
        if ($method === 'POST' && preg_match('#^/v1/orders/([^/]+)/refunds$#', $path, $m)) {
            return $this->partial_refund($m[1]);
        }

        // Dev helper for forging webhook deliveries.
        if ($method === 'POST' && preg_match('#^/__webhook/(.+)$#', $path, $m)) {
            return $this->forge_webhook($m[1]);
        }

        // Static "hosted checkout" landing page so a browser can complete a
        // checkout without a real Vatly account.
        if ($method === 'GET' && str_starts_with($path, '/__hosted/')) {
            $this->hosted_page(substr($path, strlen('/__hosted/')));

            return null;
        }

        http_response_code(404);

        return ['error' => "no mock route for {$method} {$path}"];
    }

    // --- API endpoints ---

    /** @return array<string, mixed> */
    private function create_customer(): array
    {
        $body = $this->json_body();
        $id   = 'customer_' . bin2hex(random_bytes(6));

        $customer = [
            'resource'  => 'customer',
            'id'        => $id,
            'email'     => $body['email'] ?? null,
            'name'      => $body['name'] ?? null,
            'testmode'  => true,
            'createdAt' => date('c'),
        ];

        $this->store('customers', $id, $customer);

        return $customer;
    }

    /** @return array<string, mixed> */
    private function get_customer(string $id): array
    {
        return $this->load('customers', $id) ?? [
            'resource' => 'customer',
            'id'       => $id,
            'email'    => null,
            'testmode' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function create_checkout(): array
    {
        $body = $this->json_body();
        $id   = 'checkout_' . bin2hex(random_bytes(6));

        $products = $body['products'] ?? [];
        $plan_id  = is_array($products) && isset($products[0]['id'])
            ? (string) $products[0]['id']
            : 'plan_unknown';

        $checkout = [
            'resource'            => 'checkout',
            'id'                  => $id,
            'status'              => 'open',
            'customerId'          => (string) ($body['customerId'] ?? ''),
            'redirectUrlSuccess'  => (string) ($body['redirectUrlSuccess'] ?? ''),
            'redirectUrlCanceled' => (string) ($body['redirectUrlCanceled'] ?? ''),
            'planId'              => $plan_id,
            'metadata'            => $body['metadata'] ?? null,
            'href'                => self::HOSTED_BASE . '/' . $id,
            'links'               => [
                'checkoutUrl' => ['href' => self::HOSTED_BASE . '/' . $id],
            ],
            'createdAt'           => date('c'),
            'testmode'            => true,
        ];

        $this->store('checkouts', $id, $checkout);

        return $checkout;
    }

    /** @return array<string, mixed> */
    private function get_checkout(string $id): array
    {
        $checkout = $this->load('checkouts', $id);

        if (! $checkout) {
            http_response_code(404);

            return ['error' => "checkout {$id} not found"];
        }

        return $checkout;
    }

    /** @return array<string, mixed> */
    private function get_subscription(string $id): array
    {
        return $this->load('subscriptions', $id) ?? [
            'resource'           => 'subscription',
            'id'                 => $id,
            'subscriptionPlanId' => 'plan_local_dev',
            'name'               => 'Mock Subscription',
            'quantity'           => 1,
            'status'             => 'active',
            'cancelledAt'        => null,
            'endedAt'            => null,
            'testmode'           => true,
        ];
    }

    /** @return array<string, mixed> */
    private function cancel_subscription(string $id): array
    {
        $sub = $this->load('subscriptions', $id) ?? [
            'resource' => 'subscription',
            'id'       => $id,
        ];

        $sub['status']      = 'canceled';
        $sub['cancelledAt'] = date('c');
        $sub['endedAt']     = date('c');

        $this->store('subscriptions', $id, $sub);

        return $sub;
    }

    /**
     * Fetch an order.
     *
     * Stored orders (created via the hosted-checkout flow) are returned as-is.
     * For ad-hoc ids that were never stored, we synthesize a realistic paid
     * order so the refund flows can be exercised in isolation.
     *
     * The synthesized order carries a `lines[]` array shaped exactly like the
     * real Vatly `OrderLine` resource — item-level refunds (`POST
     * /orders/{id}/refunds`) refund against these lines, so the partial-refund
     * path needs them. By default it's a single-line order (the unambiguous
     * partial-refund happy path). Pass `?lines=2` to get a two-line order for
     * exercising the multi-line branch.
     */
    /** @return array<string, mixed> */
    private function get_order(string $id): array
    {
        $stored = $this->load('orders', $id);
        if ($stored) {
            return $stored;
        }

        $lineCount = max(1, (int) ($_GET['lines'] ?? 1));

        return $this->order_payload($id, 'customer_unknown', $lineCount);
    }

    /**
     * Canonical paid-order payload, shaped to hydrate cleanly through
     * api-php's `Order`/`OrderLine` resources (Money is `{value,currency}`,
     * tax summary items are `{taxRate, amount}`).
     *
     * @param  array<string, mixed> $extra Extra top-level keys to merge in (e.g. metadata).
     * @return array<string, mixed>
     */
    private function order_payload(string $id, string $customerId, int $lineCount = 1, array $extra = []): array
    {
        $base = [
            'resource'           => 'order',
            'id'                 => $id,
            'status'             => 'paid',
            'invoiceNumber'      => 'INV-' . substr($id, 0, 6),
            'paymentMethod'      => 'creditcard',
            'customerId'         => $customerId,
            'total'              => ['value' => '23.00', 'currency' => 'EUR'],
            'subtotal'           => ['value' => '19.00', 'currency' => 'EUR'],
            'reversedSubtotal'   => ['value' => '0.00', 'currency' => 'EUR'],
            'refundableSubtotal' => ['value' => '19.00', 'currency' => 'EUR'],
            'taxSummary'         => $this->tax_summary(),
            'lines'              => $this->order_lines($id, $lineCount),
            'testmode'           => true,
        ];

        return array_merge($base, $extra);
    }

    /**
     * Build a realistic `lines[]` array matching api-php's `OrderLine` shape.
     *
     * Line ids are deterministic (`order_item_{orderId}_{n}`) so a test can
     * predict the `itemId` it expects in the partial-refund `items` payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function order_lines(string $orderId, int $count): array
    {
        $lines = [];

        for ($n = 1; $n <= $count; $n++) {
            $lines[] = [
                'id'          => 'order_item_' . $orderId . '_' . $n,
                'resource'    => 'orderline',
                'description' => 'Mock product ' . $n,
                'quantity'    => 1,
                'productType' => 'subscription',
                'productId'   => 'subscription_' . $orderId . '_' . $n,
                'basePrice'   => ['value' => '19.00', 'currency' => 'EUR'],
                'subtotal'    => ['value' => '19.00', 'currency' => 'EUR'],
                'total'       => ['value' => '23.00', 'currency' => 'EUR'],
                'taxes'       => $this->tax_summary(),
            ];
        }

        return $lines;
    }

    /**
     * Tax summary as the real API serializes it: a *bare JSON array* of
     * `{taxRate, amount}` items (not wrapped in an `items` key). api-php
     * decodes responses as objects and hydrates this list straight into a
     * `TaxSummaryCollection`, so the wrapper shape would break hydration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tax_summary(): array
    {
        return [
            [
                'taxRate' => ['name' => 'NL standard', 'percentage' => 21.0, 'taxablePercentage' => 100.0],
                'amount'  => ['value' => '4.00', 'currency' => 'EUR'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function full_refund(string $orderId): array
    {
        $refundId = 'refund_' . bin2hex(random_bytes(6));

        $refund = [
            'resource'      => 'refund',
            'id'            => $refundId,
            'orderId'       => $orderId,
            'status'        => 'refunded',
            'total'         => ['value' => '23.00', 'currency' => 'EUR'],
            'createdAt'     => date('c'),
            'testmode'      => true,
        ];

        $this->store('refunds', $refundId, $refund);

        // Fire a refund webhook to mirror real Vatly behaviour. The plugin
        // doesn't strictly need it (the createFullRefund call returns the
        // status directly), but it's useful for end-to-end testing of the
        // refund webhook reaction path.
        $webhook_url = getenv('VATLY_WEBHOOK_URL') ?: 'http://wordpress/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook';
        $this->emit_webhook($webhook_url, 'refund.completed', $refund, 'refund', $refundId);

        return $refund;
    }

    /**
     * Item-level (partial) refund: `POST /v1/orders/{id}/refunds`.
     *
     * Reads the `{items: [{itemId, amount: {value, currency}, description?}]}`
     * body the SDK sends, echoes back a `refund` resource summing the item
     * amounts, and stores it. The stored refund records the originating
     * `items` payload under `requestItems` so a test can introspect exactly
     * what the SDK posted (see the `GET /v1/orders/{id}/refunds` listing).
     *
     * @return array<string, mixed>
     */
    private function partial_refund(string $orderId): array
    {
        $body  = $this->json_body();
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];

        $currency = 'EUR';
        $total    = '0.00';
        foreach ($items as $item) {
            $amount = $item['amount'] ?? [];
            if (isset($amount['currency'])) {
                $currency = (string) $amount['currency'];
            }
            // bcadd keeps the decimal-string arithmetic exact.
            $total = function_exists('bcadd')
                ? bcadd($total, (string) ($amount['value'] ?? '0'), 2)
                : number_format((float) $total + (float) ($amount['value'] ?? 0), 2, '.', '');
        }

        $refundId = 'refund_' . bin2hex(random_bytes(6));

        $refund = [
            'resource'     => 'refund',
            'id'           => $refundId,
            'orderId'      => $orderId,
            'status'       => 'pending',
            'total'        => ['value' => $total, 'currency' => $currency],
            'createdAt'    => date('c'),
            'testmode'     => true,
            // Not part of the real Refund resource — a test seam so the
            // integration suite can assert the SDK posted the right `items`.
            'requestItems' => $items,
        ];

        $this->store('refunds', $refundId, $refund);

        return $refund;
    }

    // --- Hosted-checkout simulator ---

    private function hosted_page(string $checkout_id): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $checkout = $this->load('checkouts', $checkout_id);
        if (! $checkout) {
            http_response_code(404);
            echo "<h1>Unknown checkout</h1>";

            return;
        }

        // On click of "Pay", flip status to paid, fire webhooks, redirect.
        if (($_GET['action'] ?? '') === 'pay') {
            $checkout['status']  = 'paid';
            $checkout['orderId'] = 'order_' . bin2hex(random_bytes(6));
            $this->store('checkouts', $checkout_id, $checkout);

            // Store the order so subsequent /v1/orders/{id} requests work.
            $this->store('orders', $checkout['orderId'], $this->order_payload(
                $checkout['orderId'],
                (string) $checkout['customerId'],
                1,
                ['metadata' => $checkout['metadata'] ?? null]
            ));

            // Create a subscription so subsequent /v1/subscriptions/{id} works.
            $sub_id = 'subscription_' . bin2hex(random_bytes(6));
            $this->store('subscriptions', $sub_id, [
                'resource'           => 'subscription',
                'id'                 => $sub_id,
                'subscriptionPlanId' => $checkout['planId'],
                'name'               => 'Mock Subscription',
                'quantity'           => 1,
                'status'             => 'active',
                'cancelledAt'        => null,
                'endedAt'            => null,
                'metadata'           => $checkout['metadata'] ?? null,
                'testmode'           => true,
            ]);

            $webhook_url = getenv('VATLY_WEBHOOK_URL') ?: 'http://wordpress/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook';

            $this->emit_webhook($webhook_url, 'subscription.started', [
                'id'                 => $sub_id,
                'customerId'         => $checkout['customerId'],
                'subscriptionPlanId' => $checkout['planId'],
                'name'               => 'Mock Subscription',
                'quantity'           => 1,
                'metadata'           => $checkout['metadata'] ?? null,
            ], 'subscription', $sub_id);

            $this->emit_webhook($webhook_url, 'order.paid', [
                'id'         => $checkout['orderId'],
                'customerId' => $checkout['customerId'],
                'metadata'   => $checkout['metadata'] ?? null,
            ], 'order', $checkout['orderId']);

            header('Location: ' . $checkout['redirectUrlSuccess']);

            return;
        }

        ?>
        <!doctype html>
        <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>Mock Vatly hosted checkout</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 480px; margin: 4rem auto; padding: 2rem; border: 1px solid #ddd; border-radius: 12px; }
                    h1 { font-size: 1.25rem; margin: 0 0 1rem; }
                    .kv { color: #666; font-size: 0.875rem; }
                    .kv code { color: #111; }
                    button { background: #111; color: #fff; border: 0; padding: 0.75rem 1.25rem; border-radius: 8px; font-size: 1rem; cursor: pointer; }
                    button.cancel { background: #fff; color: #111; border: 1px solid #ddd; margin-left: 0.5rem; }
                </style>
            </head>
            <body>
                <h1>Mock Vatly hosted checkout</h1>
                <p class="kv">Checkout: <code><?= htmlspecialchars($checkout_id) ?></code></p>
                <p class="kv">Customer: <code><?= htmlspecialchars((string) ($checkout['customerId'] ?? '')) ?></code></p>
                <p class="kv">Plan: <code><?= htmlspecialchars((string) ($checkout['planId'] ?? '')) ?></code></p>
                <p>
                    <form method="get">
                        <input type="hidden" name="action" value="pay">
                        <button type="submit">Pay &euro;19.00</button>
                        <a class="cancel" href="<?= htmlspecialchars((string) $checkout['redirectUrlCanceled']) ?>">
                            <button type="button" class="cancel">Cancel</button>
                        </a>
                    </form>
                </p>
                <p class="kv" style="margin-top: 2rem;">This is the mock Vatly server. No real money will move.</p>
            </body>
        </html>
        <?php
    }

    // --- Dev helper: forge & deliver a signed webhook ---

    /** @return array<string, mixed> */
    private function forge_webhook(string $event_name): array
    {
        $body = $this->json_body();

        $target      = (string) ($body['url'] ?? (getenv('VATLY_WEBHOOK_URL') ?: 'http://wordpress/wp-admin/admin-ajax.php?action=fluent_cart_vatly_webhook'));
        $entity_type = (string) ($body['entityType'] ?? 'subscription');
        $entity_id   = (string) ($body['entityId'] ?? 'subscription_dev');
        $object      = (array) ($body['object'] ?? []);

        $delivered = $this->emit_webhook($target, $event_name, $object, $entity_type, $entity_id);

        return [
            'delivered' => $delivered['ok'],
            'status'    => $delivered['status'],
            'url'       => $target,
        ];
    }

    /**
     * @param  array<string, mixed> $object
     * @return array{ok: bool, status: int}
     */
    private function emit_webhook(string $url, string $event_name, array $object, string $entity_type, string $entity_id): array
    {
        $resource = explode('.', $event_name)[0];

        $payload = [
            'id'         => 'evt_' . bin2hex(random_bytes(6)),
            'resource'   => $resource,
            'eventName'  => $event_name,
            'entityType' => $entity_type,
            'entityId'   => $entity_id,
            'testmode'   => true,
            'createdAt'  => date('c'),
            'object'     => array_merge(['customerId' => $object['customerId'] ?? null], $object),
        ];

        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, $body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Vatly-Signature: t=' . $timestamp . ',v1=' . $signature,
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status];
    }

    private function sign(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, self::WEBHOOK_SECRET);
    }

    // --- Tiny state store ---

    /** @param array<string, mixed> $value */
    private function store(string $bucket, string $id, array $value): void
    {
        $state               = $this->state();
        $state[$bucket]      = $state[$bucket] ?? [];
        $state[$bucket][$id] = $value;

        file_put_contents($this->stateFile(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed>|null */
    private function load(string $bucket, string $id): ?array
    {
        $state = $this->state();

        return $state[$bucket][$id] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private function state(): array
    {
        $file = $this->stateFile();

        if (! file_exists($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true) ?: [];
    }

    /** @return array<string, mixed> */
    private function json_body(): array
    {
        $raw = file_get_contents('php://input') ?: '';

        return $raw === '' ? [] : (array) json_decode($raw, true);
    }
}
