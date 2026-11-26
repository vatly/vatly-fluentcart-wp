<?php
/**
 * Plugin Name:       Vatly for FluentCart
 * Plugin URI:        https://docs.vatly.com/packages/fluentcart/readme
 * Description:       Accept payments through Vatly, the European Merchant of Record for AI, software and digital products, inside FluentCart.
 * Version:           0.1.5-alpha
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

define('VATLY_FLUENTCART_VERSION', '0.1.5-alpha');
define('VATLY_FLUENTCART_FILE', __FILE__);
define('VATLY_FLUENTCART_DIR', plugin_dir_path(__FILE__));
define('VATLY_FLUENTCART_URL', plugin_dir_url(__FILE__));
define('VATLY_FLUENTCART_GATEWAY_SLUG', 'vatly');

// The release zip ships its own vendor/. A Composer-managed site (such as
// Bedrock) autoloads the plugin and its dependencies from the site's vendor/.
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} elseif (! class_exists(\Vatly\FluentCart\Plugin::class)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Vatly for FluentCart:</strong> Composer dependencies are missing. Run <code>composer install</code> inside the plugin directory, or install the plugin with Composer at the site level.</p></div>';
    });
    return;
}

register_activation_hook(__FILE__, [\Vatly\FluentCart\Support\Install::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    \Vatly\FluentCart\Plugin::instance()->boot();
}, 20);
