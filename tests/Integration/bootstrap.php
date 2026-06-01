<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Boots a real WordPress install via the canonical
 * `tests/phpunit/includes/bootstrap.php` from WordPress core (downloaded by
 * `bin/install-wp-tests.sh` to `$WP_TESTS_DIR`).
 *
 * The install script must have run first — locally that means:
 *
 *   bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
 *   WP_TESTS_DIR=/tmp/wordpress-tests-lib \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist
 *
 * The CI workflow (`.github/workflows/ci.yml`) handles this end-to-end and
 * pins the test scaffold to the exact WordPress version under test.
 *
 * NOTE: this bootstrap deliberately does NOT load `tests/WPStubs.php` or
 * `tests/FluentCartStubs.php`. WordPress is providing the real classes, and
 * the FluentCart stubs would clash with the real plugin if it were ever
 * installed alongside (the stubs file has a `class_exists` guard, but the
 * cleanest separation is just to not load it here at all).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir || ! is_dir($_tests_dir . '/includes')) {
    fwrite(STDERR, "WP_TESTS_DIR is not set or does not contain the test scaffold. Run bin/install-wp-tests.sh first.\n");
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

// Force-load our plugin during the WP test bootstrap. `muplugins_loaded`
// fires before regular plugins, which gives us the same hook-ordering as a
// real activated plugin without requiring FluentCart to be installed for
// tests that only exercise our own classes (Install, repositories).
tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        require dirname(__DIR__, 2) . '/vatly-for-fluentcart.php';
    }
);

require $_tests_dir . '/includes/bootstrap.php';
