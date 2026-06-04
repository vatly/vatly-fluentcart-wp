<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Integration;

use DateTimeImmutable;
use Vatly\FluentCart\Support\Install;
use Vatly\FluentCart\Webhook\WebhookCallRepository;
use WP_UnitTestCase;

/**
 * Round-trip the webhook-call audit log against a real MySQL.
 *
 * @covers \Vatly\FluentCart\Webhook\WebhookCallRepository
 */
class WebhookCallRepositoryIntegrationTest extends WP_UnitTestCase
{
    private WebhookCallRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        Install::activate();

        global $wpdb;
        // phpcs:ignore WordPress.DB
        $wpdb->query('TRUNCATE TABLE ' . WebhookCallRepository::tableName());

        $this->repo = new WebhookCallRepository();
    }

    public function test_record_inserts_a_row_with_expected_fields(): void
    {
        $this->record('evt_1', 'subscription.started', testmode: true);

        global $wpdb;
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                'SELECT vatly_id, event_name, testmode, vatly_customer_id, object FROM '
                . WebhookCallRepository::tableName() . ' WHERE vatly_id = %s',
                'evt_1'
            ),
            ARRAY_A
        );

        self::assertNotNull($row);
        self::assertSame('evt_1', $row['vatly_id']);
        self::assertSame('subscription.started', $row['event_name']);
        self::assertSame('1', $row['testmode']);
        self::assertSame('cus_test', $row['vatly_customer_id']);
        self::assertIsString($row['object']);
        self::assertSame(['customerId' => 'cus_test'], json_decode($row['object'], true));
    }

    public function test_record_uses_replace_so_duplicate_vatly_id_overwrites(): void
    {
        // record() uses $wpdb->replace, which is REPLACE INTO — duplicate
        // unique key is resolved by DELETE+INSERT. Two calls with the same
        // vatly_id leave exactly one row, with the SECOND payload.
        $this->record('evt_dup', 'order.paid', objectOverride: ['attempt' => 'first']);
        $this->record('evt_dup', 'order.paid', objectOverride: ['attempt' => 'second']);

        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                'SELECT object FROM ' . WebhookCallRepository::tableName() . ' WHERE vatly_id = %s',
                'evt_dup'
            ),
            ARRAY_A
        );

        self::assertCount(1, $rows, 'REPLACE must produce exactly one row per vatly_id');
        self::assertSame(['attempt' => 'second'], json_decode($rows[0]['object'], true));
    }

    public function test_cleanup_removes_rows_older_than_cutoff(): void
    {
        global $wpdb;

        // Insert one recent row via the repo, then a 30-days-ago row directly.
        $this->record('evt_recent', 'order.paid');

        $thirtyDaysAgo = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $wpdb->insert( // phpcs:ignore WordPress.DB
            WebhookCallRepository::tableName(),
            [
                'vatly_id'    => 'evt_old',
                'resource'    => 'order',
                'event_name'  => 'order.paid',
                'entity_type' => 'order',
                'entity_id'   => 'ord_old',
                'testmode'    => 0,
                'object'      => '{}',
                'created_at'  => $thirtyDaysAgo,
            ]
        );

        $deleted = $this->repo->cleanUp(7);

        self::assertSame(1, $deleted, 'cleanUp should delete exactly the old row');

        // The recent row survives.
        $surviving = $wpdb->get_var( // phpcs:ignore WordPress.DB
            'SELECT vatly_id FROM ' . WebhookCallRepository::tableName() . ' LIMIT 1'
        );
        self::assertSame('evt_recent', $surviving);
    }

    public function test_cleanup_with_no_old_rows_returns_zero(): void
    {
        $this->record('evt_1', 'order.paid');
        $this->record('evt_2', 'order.paid');

        $deleted = $this->repo->cleanUp(7);

        self::assertSame(0, $deleted);
    }

    /**
     * @param array<string, mixed>|null $objectOverride
     */
    private function record(
        string $vatlyId,
        string $eventName,
        bool $testmode = false,
        ?array $objectOverride = null,
    ): void {
        $this->repo->record(
            id: $vatlyId,
            resource: 'subscription',
            eventName: $eventName,
            entityType: 'subscription',
            entityId: 'sub_1',
            testmode: $testmode,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
            object: $objectOverride ?? ['customerId' => 'cus_test'],
            vatlyCustomerId: 'cus_test',
        );
    }
}
