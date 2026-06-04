<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\Config;

use Brain\Monkey\Functions;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\Tests\TestCase;

final class VatlyConfigTest extends TestCase
{
    public function test_with_testmode_pins_the_mode_and_selects_the_matching_key(): void
    {
        Functions\when('get_option')->justReturn([
            'payment_mode' => 'live',
            'test_api_key' => 'sk_test_123',
            'live_api_key' => 'sk_live_456',
        ]);

        $config = new VatlyConfig();

        // Settings say live → live key.
        self::assertFalse($config->isTestmode());
        self::assertSame('sk_live_456', $config->getApiKey());

        // Pinned to test mode → test key, regardless of the settings toggle.
        $pinnedTest = $config->withTestmode(true);
        self::assertTrue($pinnedTest->isTestmode());
        self::assertSame('sk_test_123', $pinnedTest->getApiKey());

        // Pinned to live mode → live key.
        $pinnedLive = $config->withTestmode(false);
        self::assertFalse($pinnedLive->isTestmode());
        self::assertSame('sk_live_456', $pinnedLive->getApiKey());

        // Original instance is untouched — withTestmode() is immutable.
        self::assertFalse($config->isTestmode());
    }
}
