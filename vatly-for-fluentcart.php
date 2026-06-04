<?php
/**
 * Plugin Name:       Vatly for FluentCart
 * Plugin URI:        https://vatly.com/integrations/fluentcart
 * Description:       Accept payments through Vatly — a European Merchant-of-Record handling VAT, invoicing and compliance — inside FluentCart.
 * Version:           0.1.0-alpha
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Vatly
 * Author URI:        https://vatly.com
 * License:           MIT
 * Text Domain:       vatly-for-fluentcart
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('VATLY_FLUENTCART_VERSION', '0.1.0-alpha');
define('VATLY_FLUENTCART_FILE', __FILE__);
define('VATLY_FLUENTCART_DIR', plugin_dir_path(__FILE__));
define('VATLY_FLUENTCART_URL', plugin_dir_url(__FILE__));
define('VATLY_FLUENTCART_GATEWAY_SLUG', 'vatly');

$autoload = __DIR__ . '/vendor/autoload.php';
if (! is_file($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Vatly for FluentCart:</strong> Composer dependencies are missing. Run <code>composer install</code> inside the plugin directory.</p></div>';
    });
    return;
}
require $autoload;

register_activation_hook(__FILE__, [\Vatly\FluentCart\Support\Install::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    \Vatly\FluentCart\Plugin::instance()->boot();
}, 20);
