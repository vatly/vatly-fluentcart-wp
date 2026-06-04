<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Integration;

use Vatly\FluentCart\Customer\FluentCartCustomerBindings;
use Vatly\FluentCart\Support\Install;
use WP_UnitTestCase;

/**
 * Round-trip the bindings table CRUD against a real MySQL — the unit suite
 * uses the wpdb stub, which can't exercise the actual DDL or upsert semantics
 * (`ON DUPLICATE KEY UPDATE`).
 *
 * @covers \Vatly\FluentCart\Customer\FluentCartCustomerBindings
 */
class CustomerBindingsIntegrationTest extends WP_UnitTestCase
{
    private FluentCartCustomerBindings $bindings;

    protected function setUp(): void
    {
        parent::setUp();

        Install::activate();

        global $wpdb;
        // Start each test from a clean table — WP_UnitTestCase rolls back the
        // per-test DB transaction, but CREATE TABLE auto-commits in MySQL so
        // rows can survive across tests.
        // phpcs:ignore WordPress.DB
        $wpdb->query('TRUNCATE TABLE ' . FluentCartCustomerBindings::tableName());

        $this->bindings = new FluentCartCustomerBindings();
    }

    public function test_bind_inserts_a_row_and_lookups_round_trip(): void
    {
        $this->bindings->bind('cus_abc', '7');

        self::assertSame('7', $this->bindings->hostCustomerIdFor('cus_abc'));
        self::assertSame('cus_abc', $this->bindings->vatlyCustomerIdFor('7'));
    }

    public function test_bind_is_idempotent_and_updates_existing_mapping(): void
    {
        $this->bindings->bind('cus_abc', '7');
        $this->bindings->bind('cus_abc', '8'); // remap to a different host customer

        self::assertSame('8', $this->bindings->hostCustomerIdFor('cus_abc'));

        global $wpdb;
        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
            'SELECT COUNT(*) FROM ' . FluentCartCustomerBindings::tableName()
        );
        self::assertSame(1, $count, 'Upsert must not duplicate the row');
    }

    public function test_record_inserts_orphan_with_null_host(): void
    {
        $this->bindings->record('cus_orphan');

        // Null host means hostCustomerIdFor returns null (empty string → null
        // per the SUT's normalization).
        self::assertNull($this->bindings->hostCustomerIdFor('cus_orphan'));

        global $wpdb;
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                'SELECT vatly_customer_id, host_customer_id FROM '
                . FluentCartCustomerBindings::tableName()
                . ' WHERE vatly_customer_id = %s',
                'cus_orphan'
            ),
            ARRAY_A
        );
        self::assertNotNull($row);
        self::assertSame('cus_orphan', $row['vatly_customer_id']);
        self::assertNull($row['host_customer_id']);
    }

    public function test_record_does_not_overwrite_an_existing_binding(): void
    {
        $this->bindings->bind('cus_abc', '7');
        $this->bindings->record('cus_abc'); // INSERT IGNORE — should be a no-op

        // The original binding still resolves; the host_customer_id wasn't
        // nulled out by `record()`.
        self::assertSame('7', $this->bindings->hostCustomerIdFor('cus_abc'));
    }

    public function test_lookups_return_null_for_unknown_ids(): void
    {
        self::assertNull($this->bindings->hostCustomerIdFor('cus_unknown'));
        self::assertNull($this->bindings->vatlyCustomerIdFor('999'));
    }
}
