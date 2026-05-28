<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Support;

use Vatly\FluentCart\Customer\FluentCartCustomerBindings;
use Vatly\FluentCart\Webhook\WebhookCallRepository;

/**
 * Activation-time installer: creates the plugin's tables via dbDelta.
 */
final class Install
{
    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $webhookCalls = WebhookCallRepository::tableName();
        dbDelta("CREATE TABLE {$webhookCalls} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vatly_id VARCHAR(191) NOT NULL,
            resource VARCHAR(64) NOT NULL,
            event_name VARCHAR(128) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(191) NOT NULL,
            testmode TINYINT(1) NOT NULL DEFAULT 0,
            vatly_created_at DATETIME NULL,
            vatly_customer_id VARCHAR(191) NULL,
            object LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_vatly_id (vatly_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_customer (vatly_customer_id),
            KEY idx_created_at (created_at)
        ) {$charset};");

        $bindings = FluentCartCustomerBindings::tableName();
        dbDelta("CREATE TABLE {$bindings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vatly_customer_id VARCHAR(191) NOT NULL,
            host_customer_id VARCHAR(64) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_vatly_customer (vatly_customer_id),
            KEY idx_host_customer (host_customer_id)
        ) {$charset};");
    }
}
