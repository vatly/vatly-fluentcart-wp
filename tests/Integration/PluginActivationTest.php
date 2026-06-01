<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Integration;

use Vatly\FluentCart\Customer\FluentCartCustomerBindings;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Support\Install;
use Vatly\FluentCart\Webhook\WebhookCallRepository;
use WP_UnitTestCase;

/**
 * @covers \Vatly\FluentCart\Support\Install
 */
class PluginActivationTest extends WP_UnitTestCase
{
    public function test_plugin_classes_autoload(): void
    {
        self::assertTrue(class_exists(Plugin::class), 'Plugin::class autoloads');
        self::assertTrue(class_exists(Install::class), 'Install::class autoloads');
        self::assertTrue(class_exists(WebhookCallRepository::class), 'WebhookCallRepository::class autoloads');
        self::assertTrue(class_exists(FluentCartCustomerBindings::class), 'FluentCartCustomerBindings::class autoloads');
    }

    public function test_activator_creates_webhook_calls_table_via_dbDelta(): void
    {
        global $wpdb;

        // dbDelta is idempotent — even if the table already exists from a
        // previous test run, this should succeed without erroring.
        Install::activate();

        $table = WebhookCallRepository::tableName();

        // NB: WP_UnitTestCase's `start_transaction` installs a query filter
        // that rewrites every `CREATE TABLE` to `CREATE TEMPORARY TABLE`, and
        // `SHOW TABLES LIKE` doesn't see temporary tables. `DESCRIBE` does.
        // phpcs:ignore WordPress.DB
        $columns = $wpdb->get_results("DESCRIBE {$table}");

        self::assertNotEmpty($columns, "Expected dbDelta to create the {$table} table.");
    }

    public function test_activator_creates_customer_bindings_table_via_dbDelta(): void
    {
        global $wpdb;

        Install::activate();

        $table = FluentCartCustomerBindings::tableName();

        // See note in test_activator_creates_webhook_calls_table_via_dbDelta.
        // phpcs:ignore WordPress.DB
        $columns = $wpdb->get_results("DESCRIBE {$table}");

        self::assertNotEmpty($columns, "Expected dbDelta to create the {$table} table.");
    }

    public function test_webhook_calls_table_has_expected_columns(): void
    {
        global $wpdb;

        Install::activate();

        $table   = WebhookCallRepository::tableName();
        $columns = $wpdb->get_col("DESCRIBE {$table}", 0);

        $expected = [
            'id',
            'vatly_id',
            'resource',
            'event_name',
            'entity_type',
            'entity_id',
            'testmode',
            'vatly_created_at',
            'vatly_customer_id',
            'object',
            'created_at',
        ];

        foreach ($expected as $column) {
            self::assertContains($column, $columns, "Missing column: {$column}");
        }
    }

    public function test_customer_bindings_table_has_expected_columns(): void
    {
        global $wpdb;

        Install::activate();

        $table   = FluentCartCustomerBindings::tableName();
        $columns = $wpdb->get_col("DESCRIBE {$table}", 0);

        $expected = [
            'id',
            'vatly_customer_id',
            'host_customer_id',
            'updated_at',
        ];

        foreach ($expected as $column) {
            self::assertContains($column, $columns, "Missing column: {$column}");
        }
    }

    public function test_activator_is_idempotent_across_repeated_runs(): void
    {
        // Sanity-check that dbDelta doesn't fail on the second invocation —
        // it's the WordPress idiom for "create or migrate" so repeated calls
        // must be safe.
        Install::activate();
        Install::activate();
        Install::activate();

        global $wpdb;
        $bindings = FluentCartCustomerBindings::tableName();
        // phpcs:ignore WordPress.DB
        $columns  = $wpdb->get_results("DESCRIBE {$bindings}");

        self::assertNotEmpty($columns, "Table {$bindings} should exist after repeated activations.");
    }
}
