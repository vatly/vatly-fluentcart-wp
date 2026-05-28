<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Customer;

use Vatly\Fluent\Contracts\CustomerBindingRepository;

/**
 * FluentCart-backed implementation of {@see CustomerBindingRepository}.
 *
 * Persists the link between Vatly customer ids and FluentCart customer ids in
 * a dedicated table (`wp_vatly_customer_bindings`). A dedicated table is used
 * — rather than a column on FluentCart's customer model — because we don't
 * own that schema and shouldn't ALTER it from a third-party plugin.
 *
 * Rows can exist with a null host id (anonymous-checkout flow / customers
 * recorded via webhook before they've been attributed back to a FluentCart
 * customer); the attribution lands later through {@see self::bind()}.
 */
final class FluentCartCustomerBindings implements CustomerBindingRepository
{
    public const TABLE = 'vatly_customer_bindings';

    public function bind(string $vatlyCustomerId, string $hostCustomerId): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::tableName() . ' (vatly_customer_id, host_customer_id, updated_at)'
                . ' VALUES (%s, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE host_customer_id = VALUES(host_customer_id), updated_at = VALUES(updated_at)',
                $vatlyCustomerId,
                $hostCustomerId,
                current_time('mysql', true)
            )
        );
    }

    public function record(string $vatlyCustomerId): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . self::tableName() . ' (vatly_customer_id, host_customer_id, updated_at)'
                . ' VALUES (%s, NULL, %s)',
                $vatlyCustomerId,
                current_time('mysql', true)
            )
        );
    }

    public function hostCustomerIdFor(string $vatlyCustomerId): ?string
    {
        global $wpdb;

        $row = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT host_customer_id FROM ' . self::tableName() . ' WHERE vatly_customer_id = %s LIMIT 1',
                $vatlyCustomerId
            )
        );

        return $row !== null && $row !== '' ? (string) $row : null;
    }

    public function vatlyCustomerIdFor(string $hostCustomerId): ?string
    {
        global $wpdb;

        $row = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT vatly_customer_id FROM ' . self::tableName() . ' WHERE host_customer_id = %s LIMIT 1',
                $hostCustomerId
            )
        );

        return $row !== null ? (string) $row : null;
    }

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }
}
