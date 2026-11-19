<?php

/**
 * Removes what Vatly for FluentCart stored when the plugin is deleted from
 * WordPress: its two tables, its settings (including the encrypted API keys),
 * its options, its Vatly product mapping and its scheduled jobs.
 *
 * Order, subscription and transaction rows stay: they are the merchant's
 * FluentCart records, and their Vatly invoice links remain the customer's
 * invoices. Self-contained on purpose: FluentCart may already be inactive.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (['vatly_webhook_calls', 'vatly_customer_bindings'] as $vatlyFluentCartTable) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix and a constant.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$vatlyFluentCartTable}");
}

foreach (['fluent_cart_vatly_settings', 'vatly_fluentcart_db_version', 'vatly_fluentcart_billing_backfill_cursor'] as $vatlyFluentCartOption) {
    delete_option($vatlyFluentCartOption);
}

// Short-lived transients (cancellations in flight). Expired ones stay in the
// options table until read, so remove them by prefix.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_vatly_fluentcart_') . '%',
    $wpdb->esc_like('_transient_timeout_vatly_fluentcart_') . '%'
));

// FluentCart keeps gateway settings in its own meta table.
$vatlyFluentCartMeta = $wpdb->prefix . 'fct_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vatlyFluentCartMeta)) === $vatlyFluentCartMeta) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->delete($vatlyFluentCartMeta, ['meta_key' => 'fluent_cart_payment_settings_vatly', 'object_type' => 'option']);
}

delete_post_meta_by_key('_vatly_product_id');
delete_post_meta_by_key('_vatly_plan_id');

foreach (['vatly_fluentcart_prune_webhook_calls', 'vatly_fluentcart_backfill_subscription_billing'] as $vatlyFluentCartHook) {
    wp_clear_scheduled_hook($vatlyFluentCartHook);
}
