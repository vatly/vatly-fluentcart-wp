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

    /** Daily cron that deletes logged webhooks past the retention period. */
    public const PRUNE_HOOK = 'vatly_fluentcart_prune_webhook_calls';

    /**
     * Days a logged webhook is kept. The log holds Vatly's full event JSON,
     * customer name and address included, and nothing reads it back, so it is
     * kept only as long as it helps debugging. Filter:
     * `vatly_fluentcart_webhook_log_retention_days`.
     */
    public const RETENTION_DAYS = 30;

    public static function registerPruning(): void
    {
        add_action(self::PRUNE_HOOK, static function (): void {
            $days = (int) apply_filters('vatly_fluentcart_webhook_log_retention_days', self::RETENTION_DAYS);
            (new self())->cleanUp(max($days, 1));
        });

        if (! wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

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
