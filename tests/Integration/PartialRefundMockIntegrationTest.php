<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Integration;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\Refund;
use ReflectionClass;
use RuntimeException;
use Vatly\Fluent\Configuration\ArrayConfiguration;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Wiring;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\RefundService;
use Vatly\FluentCart\Plugin;
use WP_UnitTestCase;

/**
 * Closes the integration-test gap that issue #4 (partial refunds) deferred.
 *
 * Unlike the unit suite — which mocks the api-php endpoints — this test drives
 * the real {@see RefundService::refund()} entrypoint against a **live mock
 * Vatly HTTP server** (`docker/mock-vatly/server.php`) booted with `php -S`.
 * That exercises the full partial-refund wire path end to end:
 *
 *   1. real api-php client `GET /v1/orders/{id}` → hydrates `lines[]`,
 *   2. single-line detection + amount attribution,
 *   3. real api-php client `POST /v1/orders/{id}/refunds` with the `items`
 *      payload (item-level endpoint, *not* `/refunds/full`),
 *   4. the local FluentCart refund record.
 *
 * It asserts both sides: the mock received the expected `items` payload
 * (read back from the mock's persisted state file), and the refund was
 * recorded on the FluentCart transaction with the *partial* amount.
 *
 * **Scope.** This is an HTTP-against-mock integration test, not a full
 * WordPress/FluentCart end-to-end test: FluentCart is not installed in the
 * integration harness, so the `OrderTransaction` / `Refund` models come from
 * `tests/FluentCartStubs.php` (the same seam the unit suite uses). Everything
 * between the gateway entrypoint and the HTTP wire is real.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\RefundService
 */
class PartialRefundMockIntegrationTest extends WP_UnitTestCase
{
    /** @var resource|null */
    private $mockProcess = null;

    /** @var array<int, resource> */
    private array $mockPipes = [];

    private string $stateFile = '';

    private int $mockPort = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The integration bootstrap deliberately skips the FluentCart stubs;
        // load them here so the gateway's model/record sink (OrderTransaction,
        // Refund::createOrRecordRefund) exists. The stub file is guarded
        // against a real FluentCart being present.
        require_once dirname(__DIR__) . '/FluentCartStubs.php';

        Refund::$lastRecorded = null;

        $this->stateFile = (string) tempnam(sys_get_temp_dir(), 'mock-vatly-state-');
        // tempnam creates the file; the mock recreates it on first write.
        @unlink($this->stateFile);

        $this->startMockServer();
    }

    protected function tearDown(): void
    {
        $this->stopMockServer();

        if ($this->stateFile !== '') {
            @unlink($this->stateFile);
        }

        parent::tearDown();
    }

    public function test_partial_refund_hits_item_level_endpoint_and_records_refund(): void
    {
        $service = $this->serviceAgainstMock();

        // 23.00 order total (cents), refund 5.00 → partial → item-level route.
        $transaction = $this->transaction('order_partial_it', 2300);

        $result = $service->refund($transaction, 500, ['reason' => 'Customer changed mind']);

        // --- Gateway contract: success array, partial amount recorded. ---
        self::assertIsArray($result, 'Partial refund should succeed against the mock');
        self::assertTrue($result['success']);

        self::assertNotNull(Refund::$lastRecorded, 'A FluentCart refund must be recorded');
        [$recorded, $parent] = Refund::$lastRecorded;
        self::assertSame('vatly', $recorded['payment_method']);
        self::assertSame(500, $recorded['total'], 'Records the partial amount, not the order total');
        self::assertSame('pending', $recorded['status'], 'Mock item-level refund starts pending');
        self::assertNotEmpty($recorded['vendor_charge_id']);
        self::assertStringStartsWith('refund_', (string) $recorded['vendor_charge_id']);
        self::assertSame($transaction, $parent);

        // --- Wire contract: the mock received the right item-level payload. ---
        $refund = $this->lastMockRefund();

        self::assertSame('order_partial_it', $refund['orderId']);
        self::assertSame('pending', $refund['status']);
        self::assertSame('5.00', $refund['total']['value']);
        self::assertSame('EUR', $refund['total']['currency']);

        // `requestItems` is the mock's record of exactly what the SDK posted.
        self::assertArrayHasKey('requestItems', $refund, 'Mock must record the posted items payload');
        self::assertCount(1, $refund['requestItems'], 'Single-line order → one refund item');

        $item = $refund['requestItems'][0];
        self::assertSame('order_item_order_partial_it_1', $item['itemId']);
        self::assertSame(['value' => '5.00', 'currency' => 'EUR'], $item['amount']);
        self::assertSame('Customer changed mind', $item['description']);
    }

    // --- helpers ---

    /**
     * A RefundService whose Plugin is wired to a real Vatly client pointed at
     * the live mock server (real api-php → HTTP → mock). Mirrors the unit
     * suite's reflective Plugin assembly, but with a real client.
     */
    private function serviceAgainstMock(): RefundService
    {
        $vatly = new Vatly(new Wiring(
            config: new ArrayConfiguration([
                'api_key'     => 'test_0123456789abcdef0123',
                'api_url'     => 'http://127.0.0.1:' . $this->mockPort,
                'api_version' => 'v1',
            ]),
        ));

        $ref    = new ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($plugin, new VatlyConfig());

        $vatlyProp = $ref->getProperty('vatly');
        $vatlyProp->setAccessible(true);
        $vatlyProp->setValue($plugin, $vatly);

        return new RefundService($plugin);
    }

    private function transaction(string $orderId, int $total): OrderTransaction
    {
        $tx                   = new OrderTransaction();
        $tx->id               = 5;
        $tx->uuid             = 'txn-uuid';
        $tx->vendor_charge_id = $orderId;
        $tx->total            = $total;
        $tx->currency         = 'EUR';
        $tx->payment_mode     = 'live';

        return $tx;
    }

    /**
     * The most recently stored refund in the mock's persisted state.
     *
     * @return array<string, mixed>
     */
    private function lastMockRefund(): array
    {
        self::assertFileExists($this->stateFile, 'Mock should have persisted state');

        $state   = json_decode((string) file_get_contents($this->stateFile), true) ?: [];
        $refunds = $state['refunds'] ?? [];

        self::assertNotEmpty($refunds, 'Mock recorded no refund');

        return (array) end($refunds);
    }

    private function startMockServer(): void
    {
        $this->mockPort = $this->freePort();

        $router = dirname(__DIR__, 2) . '/docker/mock-vatly/router.php';
        $php    = (string) (PHP_BINARY ?: 'php');

        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg($php),
            $this->mockPort,
            escapeshellarg($router)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $cmd,
            $descriptors,
            $this->mockPipes,
            null,
            ['MOCK_VATLY_STATE_FILE' => $this->stateFile] + $_ENV
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start mock Vatly server');
        }

        $this->mockProcess = $process;

        // Poll the server until it answers (built-in server needs a moment).
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->mockPort, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('Mock Vatly server did not come up on port ' . $this->mockPort);
    }

    private function stopMockServer(): void
    {
        foreach ($this->mockPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->mockPipes = [];

        if (is_resource($this->mockProcess)) {
            proc_terminate($this->mockProcess);
            proc_close($this->mockProcess);
            $this->mockProcess = null;
        }
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (! is_resource($sock)) {
            throw new RuntimeException('Could not allocate a free port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException('Could not determine a free port');
        }

        return $port;
    }
}
