<?php
/**
 * php-scoper configuration for the release build.
 *
 * Wraps every namespaced symbol in our plugin + its vendor dependencies in a
 * `Vatly\FluentCart\Vendor\…` prefix so we never collide with another
 * WordPress plugin that ships an incompatible version of the same library
 * (most importantly `composer/ca-bundle` today, and any HTTP / Symfony /
 * Monolog / Guzzle dep that vatly-fluent-php may pick up as it stabilises).
 *
 * Why we scope our own `Vatly\Fluent\…` and `Vatly\API\…` namespaces too:
 *
 * If we excluded them, php-scoper would leave their internal references to
 * `Composer\CaBundle\CaBundle` intact — but we'd have rewritten that class to
 * `Vatly\FluentCart\Vendor\Composer\CaBundle\CaBundle`. The lookup would
 * then fail at runtime. Scoping the entire graph (vendor + our src) is
 * simpler and leaves no holes.
 *
 * Why FluentCart's own namespace IS excluded:
 *
 * FluentCart is installed as a separate WordPress plugin alongside ours.
 * Its classes (FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway,
 * FluentCart\App\Models\OrderTransaction, …) live at the unprefixed paths
 * in their plugin's autoloader. If we prefixed our `use` statements that
 * point at them, our `VatlyGateway extends AbstractPaymentGateway` would
 * try to load `Vatly\FluentCart\Vendor\FluentCart\App\…` and fatal at
 * activation.
 *
 * See: https://github.com/humbug/php-scoper
 *
 * @package Vatly\FluentCart
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Vatly\\FluentCart\\Vendor',

    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/\.(md|dist|neon|xml|json|lock|yml|yaml|txt|sh|sample)$/')
            ->exclude([
                'doc',
                'test',
                'tests',
                'Test',
                'Tests',
                'test_old',
                'vendor-bin',
            ])
            ->in('vendor'),

        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->in('src'),

        Finder::create()
            ->files()
            ->name('vatly-for-fluentcart.php')
            ->depth('== 0')
            ->in('.'),
    ],

    /*
     * Keep FluentCart's own namespace unprefixed — the live plugin's
     * autoloader serves those classes at the canonical path. WP core lives
     * in the global namespace (functions, WP_User, WP_REST_Request, etc.);
     * php-scoper already leaves global functions alone by default.
     *
     * `Composer\Autoload` and `Composer\InstalledVersions` MUST be excluded:
     * the build pipeline runs `composer dump-autoload` AFTER scoping, which
     * regenerates `vendor/composer/{ClassLoader,InstalledVersions}.php` and
     * `autoload_{real,static}.php` UNPREFIXED. If scoper prefixed them
     * first, we end up with a classmap that has both prefixed AND
     * unprefixed entries pointing at the same files — site fatals on
     * "Cannot redeclare class" the moment anything probes
     * `Composer\InstalledVersions` (Symfony / Monolog / Guzzle do this
     * routinely).
     */
    'exclude-namespaces' => [
        'FluentCart',
        'WP',
        // `Composer\Autoload` (ClassLoader.php) and bare `Composer`
        // (InstalledVersions.php) must stay unprefixed: `composer
        // dump-autoload` regenerates them anyway and Composer's runtime
        // helpers are introspected by bare name across the ecosystem.
        // Regex `^Composer$` matches ONLY the bare `Composer` namespace, so
        // `Composer\CaBundle` etc. still get prefixed.
        '/^Composer$/',
        'Composer\\Autoload',
    ],

    'exclude-classes' => [
        '/^WP_/',
        'wpdb',
    ],

    'exclude-functions' => [
        // Match everything global — WordPress's API surface is enormous and
        // php-scoper's default is already to leave global functions alone,
        // but we make this explicit so future changes don't accidentally
        // scope something like `__()` or `add_action()` or
        // `fluent_cart_api()`.
        '/.*/',
    ],

    'exclude-constants' => [
        '/^WP_/',
        '/^WPINC$/',
        '/^ABSPATH$/',
        '/^VATLY_FLUENTCART_/',
    ],
];
