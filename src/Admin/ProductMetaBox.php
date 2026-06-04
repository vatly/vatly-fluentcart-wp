<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Admin;

/**
 * Adds a Vatly mapping meta box to the FluentCart product edit screen so
 * merchants can enter the Vatly product ID (for one-time products) and Vatly
 * plan ID (for subscriptions) without touching SQL.
 *
 * Post type name 'fc_product' is FluentCart's product CPT; if their slug
 * differs in some installs, adjust here.
 */
final class ProductMetaBox
{
    public const POST_TYPE = 'fc_product';

    public const NONCE_ACTION = 'vatly_for_fluentcart_product_meta';
    public const NONCE_NAME   = 'vatly_for_fluentcart_product_meta_nonce';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save'], 10, 2);
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            'vatly-fluentcart-mapping',
            __('Vatly mapping', 'vatly-for-fluentcart'),
            [$this, 'render'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $productId = get_post_meta($post->ID, '_vatly_product_id', true);
        $planId    = get_post_meta($post->ID, '_vatly_plan_id', true);

        ?>
        <p>
            <label for="vatly_product_id"><strong><?php esc_html_e('Vatly product ID', 'vatly-for-fluentcart'); ?></strong></label>
            <input type="text" id="vatly_product_id" name="vatly_product_id"
                   value="<?php echo esc_attr((string) $productId); ?>"
                   placeholder="product_..."
                   class="widefat" />
            <span class="description"><?php esc_html_e('Used for one-time payments.', 'vatly-for-fluentcart'); ?></span>
        </p>
        <p>
            <label for="vatly_plan_id"><strong><?php esc_html_e('Vatly plan ID', 'vatly-for-fluentcart'); ?></strong></label>
            <input type="text" id="vatly_plan_id" name="vatly_plan_id"
                   value="<?php echo esc_attr((string) $planId); ?>"
                   placeholder="plan_..."
                   class="widefat" />
            <span class="description"><?php esc_html_e('Used for recurring subscriptions.', 'vatly-for-fluentcart'); ?></span>
        </p>
        <?php
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if (! isset($_POST[self::NONCE_NAME])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        $productId = isset($_POST['vatly_product_id']) ? sanitize_text_field(wp_unslash($_POST['vatly_product_id'])) : '';
        $planId    = isset($_POST['vatly_plan_id'])    ? sanitize_text_field(wp_unslash($_POST['vatly_plan_id']))    : '';

        update_post_meta($postId, '_vatly_product_id', $productId);
        update_post_meta($postId, '_vatly_plan_id', $planId);
    }
}
