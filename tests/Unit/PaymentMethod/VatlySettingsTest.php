<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit\PaymentMethod;

use Brain\Monkey\Functions;
use Vatly\FluentCart\Config\VatlyConfig;
use Vatly\FluentCart\PaymentMethod\VatlySettings;
use Vatly\FluentCart\Tests\TestCase;

/**
 * FluentCart 2.2.0's checkout gate (PaymentMethods::getActiveMethodInstance)
 * and settings UI read `is_active` as the string 'yes'/'no'. This gateway
 * stores its settings in its own WP option with a boolean `is_active`, so
 * VatlySettings::get() must normalise to the string form on the way out —
 * otherwise the method is silently treated as inactive and never offered at
 * checkout.
 *
 * @covers \Vatly\FluentCart\PaymentMethod\VatlySettings
 */
final class VatlySettingsTest extends TestCase
{
    private function withOption(array $option): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use ($option) {
            return $key === VatlyConfig::OPTION_KEY ? $option : $default;
        });
    }

    public function test_boolean_true_is_active_normalises_to_yes(): void
    {
        $this->withOption(['is_active' => true, 'payment_mode' => 'live']);

        $settings = new VatlySettings();

        self::assertSame('yes', $settings->get('is_active'));
        self::assertTrue($settings->isActive());
    }

    public function test_missing_or_falsey_is_active_normalises_to_no(): void
    {
        $this->withOption(['payment_mode' => 'test']);

        $settings = new VatlySettings();

        self::assertSame('no', $settings->get('is_active'));
        self::assertFalse($settings->isActive());
    }

    public function test_get_with_no_key_returns_the_whole_normalised_array(): void
    {
        $this->withOption(['is_active' => false, 'payment_mode' => 'live']);

        $all = (new VatlySettings())->get();

        self::assertSame('no', $all['is_active']);
        self::assertSame('live', $all['payment_mode']);
    }

    public function test_get_mode_returns_stored_mode_and_defaults_to_test(): void
    {
        $this->withOption(['payment_mode' => 'live']);
        self::assertSame('live', (new VatlySettings())->getMode());

        $this->withOption([]);
        self::assertSame('test', (new VatlySettings())->getMode());
    }
}
