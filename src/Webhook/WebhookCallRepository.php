<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook;

use DateTimeInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;

/**
 * Persists raw webhook deliveries for audit and replay. Lives in its own table
 * — independent of FluentCart's data model — so we can debug signature/parse
 * failures and replay against a customer's order history if needed.
 */
final class WebhookCallRepository implements WebhookCallRepositoryInterface
{
    public const TABLE = 'vatly_webhook_calls';

    public function record(
        string $id,
        string $resource,
        string $eventName,
        string $entityType,
        string $entityId,
        bool $testmode,
        DateTimeInterface $createdAt,
        array $object,
        ?string $vatlyCustomerId = null,
    ): void {
        global $wpdb;

        $wpdb->replace(
            $this->tableName(),
            [
                'vatly_id'           => $id,
                'resource'           => $resource,
                'event_name'         => $eventName,
                'entity_type'        => $entityType,
                'entity_id'          => $entityId,
                'testmode'           => $testmode ? 1 : 0,
                'vatly_created_at'   => $createdAt->format('Y-m-d H:i:s'),
                'vatly_customer_id'  => $vatlyCustomerId,
                'object'             => (string) wp_json_encode($object),
                'created_at'         => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
        );
    }

    public function cleanUp(int $days = 7): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        // Table identifier comes from $wpdb->prefix + a class constant — never user input — and the cutoff value uses %s.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . $this->tableName() . ' WHERE created_at < %s',
                $cutoff
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $deleted;
    }

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }
}
