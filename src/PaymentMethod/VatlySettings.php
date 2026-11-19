<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

/**
 * The Vatly gateway's saved settings.
 *
 * FluentCart's settings screen saves them with `fluent_cart_update_option()`
 * under {@see self::METHOD_HANDLER} (see
 * `AbstractPaymentGateway::updateSettings()`), and `BaseGatewaySettings` loads
 * them back. `is_active` is `'yes'` / `'no'`, like FluentCart's own gateways.
 *
 * Test or live follows FluentCart's store mode (Settings → Store mode), as it
 * does for FluentCart's built-in gateways, so the Vatly key in use always
 * matches the mode FluentCart stamps on the order.
 */
final class VatlySettings extends BaseGatewaySettings
{
    public const METHOD_HANDLER = 'fluent_cart_payment_settings_vatly';

    /** @var string */
    public $methodHandler = self::METHOD_HANDLER;

    /**
     * @return array<string, string>
     */
    public static function getDefaults(): array
    {
        return [
            'is_active'           => 'no',
            'test_api_key'        => '',
            'test_webhook_secret' => '',
            'live_api_key'        => '',
            'live_webhook_secret' => '',
        ];
    }

    /**
     * @return mixed The whole settings array when `$key` is empty.
     */
    public function get($key = '')
    {
        if ($key === '' || $key === null) {
            return $this->settings;
        }

        return $this->settings[$key] ?? null;
    }

    public function getMode(): string
    {
        return (new StoreSettings())->get('order_mode') === 'live' ? 'live' : 'test';
    }

    public function isActive(): bool
    {
        return ($this->settings['is_active'] ?? 'no') === 'yes';
    }
}
