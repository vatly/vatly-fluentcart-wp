<?php
/**
 * PHPUnit unit-suite bootstrap.
 *
 * Loads composer's autoloader, the runtime WordPress shim, and the FluentCart
 * stubs so the SUT can be `require`d without a WordPress or FluentCart install.
 * Integration tests (PR C) will use a separate `tests/Integration/bootstrap.php`
 * with the real WP test scaffold — that bootstrap must NOT load
 * `FluentCartStubs.php` because the namespaced classes inside would clash
 * with the real FluentCart plugin's.
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! file_exists($autoload)) {
    fwrite(STDERR, "Composer dependencies are not installed. Run `composer install` first.\n");
    exit(1);
}

// Patchwork must load BEFORE any file that defines functions Brain\Monkey
// will redefine — otherwise it raises `DefinedTooEarly`.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

require_once $autoload;

require_once __DIR__ . '/WPStubs.php';
require_once __DIR__ . '/FluentCartStubs.php';
