<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use FluentCart\Api\StoreSettings;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\VatlySettings;
use Vatly\FluentCart\Tests\TestCase;

/**
 * FluentCart saves a gateway's settings screen under its `methodHandler` in
 * its own option store, and its checkout only offers a method whose
 * `is_active` is 'yes'. The plugin must read the keys from that same store,
 * or settings a merchant saves never take effect.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\VatlySettings
 * @covers \Vatly\FluentCart\Config\VatlyConfig
 */
final class VatlySettingsTest extends TestCase
{
    public function test_settings_saved_by_fluentcart_reach_the_gateway_and_the_sdk_config(): void
    {
        $GLOBALS['fluent_cart_options'][VatlySettings::METHOD_HANDLER] = [
            'is_active'           => 'yes',
            'test_api_key'        => 'test_key_1',
            'test_webhook_secret' => 'whsec_test',
            'live_api_key'        => 'live_key_1',
            'live_webhook_secret' => 'whsec_live',
        ];

        self::assertTrue((new VatlySettings())->isActive());

        $config = new VatlyConfig();
        self::assertTrue($config->isEnabled());
        self::assertSame('test_key_1', $config->getApiKey());
        self::assertSame('whsec_test', $config->getWebhookSecret());
    }

    public function test_unsaved_settings_leave_the_gateway_inactive(): void
    {
        self::assertFalse((new VatlySettings())->isActive());
        self::assertNull((new VatlyConfig())->getWebhookSecret());
    }

    public function test_mode_follows_the_store_order_mode(): void
    {
        self::assertSame('test', (new VatlySettings())->getMode());

        StoreSettings::$orderMode = 'live';
        self::assertSame('live', (new VatlySettings())->getMode());
    }
}
