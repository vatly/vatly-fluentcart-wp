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

    public function test_saving_encrypts_the_keys_and_the_config_reads_them_back(): void
    {
        $saved = \Vatly\FluentCart\PaymentMethod\VatlyGateway::beforeSettingsUpdate([
            'is_active'           => 'yes',
            'test_api_key'        => 'test_key_1',
            'test_webhook_secret' => 'whsec_test',
            'live_api_key'        => '',
        ], []);

        self::assertNotSame('test_key_1', $saved['test_api_key']);
        self::assertNotSame('whsec_test', $saved['test_webhook_secret']);
        self::assertSame('', $saved['live_api_key']);
        self::assertSame($saved, \Vatly\FluentCart\PaymentMethod\VatlyGateway::beforeSettingsUpdate($saved, $saved), 'saving again does not encrypt twice');

        $GLOBALS['fluent_cart_options'][VatlySettings::METHOD_HANDLER] = $saved;
        $config = new VatlyConfig();
        self::assertSame('test_key_1', $config->getApiKey());
        self::assertSame('whsec_test', $config->getWebhookSecret());
    }

    public function test_plain_text_settings_from_older_builds_move_into_fluentcart_encrypted(): void
    {
        $options = [VatlySettings::LEGACY_OPTION => ['is_active' => true, 'payment_mode' => 'test', 'test_api_key' => 'test_key_1', 'test_webhook_secret' => 'whsec_test']];
        \Brain\Monkey\Functions\when('get_option')->alias(static function ($key, $default = false) use (&$options) {
            return $options[$key] ?? $default;
        });
        \Brain\Monkey\Functions\when('delete_option')->alias(static function ($key) use (&$options) {
            unset($options[$key]);
            return true;
        });
        \Brain\Monkey\Functions\when('fluent_cart_update_option')->alias(static function ($key, $value) {
            $GLOBALS['fluent_cart_options'][$key] = $value;
            return true;
        });

        VatlySettings::migrateLegacyOption();

        $stored = $GLOBALS['fluent_cart_options'][VatlySettings::METHOD_HANDLER];
        self::assertArrayNotHasKey(VatlySettings::LEGACY_OPTION, $options);
        self::assertSame('yes', $stored['is_active']);
        self::assertNotSame('test_key_1', $stored['test_api_key']);
        self::assertSame('test_key_1', (new VatlyConfig())->getApiKey());
    }
}
