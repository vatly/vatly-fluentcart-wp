<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Reset the global wpdb stub between tests so staged values don't bleed.
        if (isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'reset')) {
            $GLOBALS['wpdb']->reset();
        }

        $this->setupCommonStubs();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Brain\Monkey expectations don't show up as PHPUnit assertions, which
     * trips the suite's `failOnRisky` mode. Tests whose only assertion is a
     * `Functions\expect(...)` call should invoke this once.
     */
    protected function assertHookExpectations(): void
    {
        self::assertTrue(true, 'Brain\Monkey expectations verified during tearDown.');
    }

    /**
     * Stub the WP functions every SUT touches casually. Tests can still
     * override any of these via `Functions\when()` / `expect()`.
     */
    protected function setupCommonStubs(): void
    {
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);
        Monkey\Functions\when('esc_attr')->returnArg(1);
        Monkey\Functions\when('esc_url_raw')->returnArg(1);
        Monkey\Functions\when('esc_html')->returnArg(1);
        Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        Monkey\Functions\when('wp_unslash')->returnArg(1);
        Monkey\Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Monkey\Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Monkey\Functions\when('wp_json_encode')->alias('json_encode');
        Monkey\Functions\when('home_url')->returnArg(1);
        Monkey\Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . '?' . http_build_query($args);
        });
        Monkey\Functions\when('rest_url')->alias(static fn(string $path) => "https://example.org/wp-json/{$path}");
        Monkey\Functions\when('trailingslashit')->alias(static fn(string $s) => rtrim($s, '/') . '/');
        Monkey\Functions\when('plugin_dir_path')->returnArg(1);
        Monkey\Functions\when('plugin_dir_url')->returnArg(1);
        Monkey\Functions\when('current_user_can')->justReturn(true);
    }
}
