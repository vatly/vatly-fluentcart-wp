<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Config;

use FluentCart\Api\StoreSettings;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\VatlySettings;
use Vatly\FluentCart\Tests\TestCase;

final class VatlyConfigTest extends TestCase
{
    public function test_reads_fluentcart_saved_settings_and_follows_store_mode(): void
    {
        $GLOBALS['fluent_cart_options'][VatlySettings::METHOD_HANDLER] = [
            'is_active'    => 'yes',
            'test_api_key' => 'test_123',
            'live_api_key' => 'live_456',
        ];
        StoreSettings::$orderMode = 'live';

        $config = new VatlyConfig();

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isTestmode());
        self::assertSame('live_456', $config->getApiKey());

        // Pinned to test mode: test key, whatever the store mode.
        $pinnedTest = $config->withTestmode(true);
        self::assertTrue($pinnedTest->isTestmode());
        self::assertSame('test_123', $pinnedTest->getApiKey());

        // withTestmode() is immutable.
        self::assertFalse($config->isTestmode());
    }

    public function test_is_disabled_unless_fluentcart_saved_is_active_yes(): void
    {
        $GLOBALS['fluent_cart_options'][VatlySettings::METHOD_HANDLER] = ['is_active' => 'no'];

        self::assertFalse((new VatlyConfig())->isEnabled());
    }
}
