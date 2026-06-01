<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Customer;

use Vatly\FluentCart\Customer\FluentCartCustomerBindings;
use Vatly\FluentCart\Tests\TestCase;

/**
 * @covers \Vatly\FluentCart\Customer\FluentCartCustomerBindings
 */
class FluentCartCustomerBindingsTest extends TestCase
{
    public function test_bind_issues_an_upsert_against_the_bindings_table(): void
    {
        global $wpdb;

        (new FluentCartCustomerBindings())->bind('cus_abc', '7');

        self::assertCount(1, $wpdb->queries);
        [$op, $args] = $wpdb->queries[0];
        self::assertSame('query', $op);

        $sql = $args[0];
        self::assertStringContainsString('INSERT INTO', $sql);
        self::assertStringContainsString('wp_vatly_customer_bindings', $sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsString("'cus_abc'", $sql);
        self::assertStringContainsString("'7'", $sql);
    }

    public function test_record_issues_an_insert_ignore_with_null_host(): void
    {
        global $wpdb;

        (new FluentCartCustomerBindings())->record('cus_orphan');

        self::assertCount(1, $wpdb->queries);
        [$op, $args] = $wpdb->queries[0];
        self::assertSame('query', $op);

        $sql = $args[0];
        self::assertStringContainsString('INSERT IGNORE INTO', $sql);
        self::assertStringContainsString('wp_vatly_customer_bindings', $sql);
        self::assertStringContainsString("'cus_orphan'", $sql);
        self::assertStringContainsString('NULL', $sql);
    }

    public function test_host_customer_id_for_returns_the_staged_value(): void
    {
        global $wpdb;
        $wpdb->next_var = '42';

        self::assertSame('42', (new FluentCartCustomerBindings())->hostCustomerIdFor('cus_abc'));

        [$op, $args] = $wpdb->queries[0];
        self::assertSame('get_var', $op);
        self::assertStringContainsString('SELECT host_customer_id FROM', $args[0]);
        self::assertStringContainsString("'cus_abc'", $args[0]);
    }

    public function test_host_customer_id_for_returns_null_when_no_row_or_empty(): void
    {
        global $wpdb;

        // No staged value → wpdb returns null.
        self::assertNull((new FluentCartCustomerBindings())->hostCustomerIdFor('cus_unknown'));

        // Empty string stored is treated as "no binding".
        $wpdb->reset();
        $wpdb->next_var = '';
        self::assertNull((new FluentCartCustomerBindings())->hostCustomerIdFor('cus_empty'));
    }

    public function test_vatly_customer_id_for_returns_the_staged_value(): void
    {
        global $wpdb;
        $wpdb->next_var = 'cus_xyz';

        self::assertSame('cus_xyz', (new FluentCartCustomerBindings())->vatlyCustomerIdFor('7'));

        [$op, $args] = $wpdb->queries[0];
        self::assertSame('get_var', $op);
        self::assertStringContainsString('SELECT vatly_customer_id FROM', $args[0]);
        self::assertStringContainsString("'7'", $args[0]);
    }

    public function test_vatly_customer_id_for_returns_null_when_missing(): void
    {
        self::assertNull((new FluentCartCustomerBindings())->vatlyCustomerIdFor('999'));
    }
}
