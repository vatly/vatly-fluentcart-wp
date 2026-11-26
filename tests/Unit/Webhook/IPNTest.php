<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Webhook;

use Brain\Monkey\Functions;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\Plugin;
use Vatly\FluentCart\Tests\TestCase;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\FluentCart\Webhook\IPN;
use Vatly\FluentCart\Webhook\SubscriptionNotFoundException;

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

    public function test_says_the_api_key_is_missing_instead_of_failing_every_webhook(): void
    {
        $GLOBALS['fluent_cart_options'][\Vatly\FluentCart\PaymentMethod\VatlySettings::METHOD_HANDLER] = [
            'test_webhook_secret' => 'whsec',
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

        (new IPN($plugin))->handle();

        self::assertSame([503, ['error' => 'api_key_not_configured']], $sent);
    }

    public function test_answers_404_when_the_webhook_names_a_subscription_this_site_does_not_know(): void
    {
        // Vatly retries any non-2xx, so the 404 still gets it redelivered; a
        // 500 stays reserved for real processing failures.
        $sent = $this->handleWhileProcessorThrows(new SubscriptionNotFoundException('subscription_unknown is not known'));

        self::assertSame([404, ['error' => 'subscription_not_found']], $sent);
    }

    public function test_other_processing_failures_still_answer_500(): void
    {
        $sent = $this->handleWhileProcessorThrows(new \RuntimeException('could not load the Vatly order'));

        self::assertSame([500, ['error' => 'processing_failed']], $sent);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    private function handleWhileProcessorThrows(\Throwable $error): ?array
    {
        $GLOBALS['fluent_cart_options'][\Vatly\FluentCart\PaymentMethod\VatlySettings::METHOD_HANDLER] = [
            'test_api_key'        => 'test_key',
            'test_webhook_secret' => 'whsec',
        ];

        $processor = \Mockery::mock(WebhookProcessor::class);
        $processor->shouldReceive('handle')->once()->andThrow($error);
        $vatly = \Mockery::mock(Vatly::class);
        $vatly->shouldReceive('webhookProcessor')->andReturn($processor);

        $ref    = new \ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();
        foreach (['config' => new VatlyConfig(), 'vatly' => $vatly] as $name => $value) {
            $property = $ref->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($plugin, $value);
        }

        $sent = null;
        Functions\when('error_log')->justReturn(true);
        Functions\when('status_header')->justReturn(null);
        Functions\when('wp_send_json')->alias(static function ($body, $status) use (&$sent): void {
            $sent = [$status, $body];
        });

        (new IPN($plugin))->handle();

        return $sent;
    }
}
