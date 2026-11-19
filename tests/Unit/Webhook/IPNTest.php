<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\FluentCart\Webhook\IPN;

/**
 * An empty secret would make the signature check accept an HMAC keyed with
 * '', which anyone can compute. Without a configured secret the endpoint must
 * refuse every webhook before it reaches the processor.
 *
 * @covers \Vatly\FluentCart\Webhook\IPN
 */
final class IPNTest extends TestCase
{
    public function test_refuses_webhooks_while_no_secret_is_configured(): void
    {
        $GLOBALS['fluent_cart_options'][\Vatly\FluentCart\PaymentMethod\VatlySettings::METHOD_HANDLER] = [
            'test_api_key' => 'test_key',
        ];

        $ref    = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($plugin, new VatlyConfig());

        $sent = null;
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_send_json')->alias(static function ($body, $status) use (&$sent): void {
            $sent = [$status, $body];
        });

        // The processor is never built: Plugin::$vatly is unset, so reaching it would throw.
        (new IPN($plugin))->handle();

        self::assertSame([503, ['error' => 'webhook_secret_not_configured']], $sent);
    }
}
