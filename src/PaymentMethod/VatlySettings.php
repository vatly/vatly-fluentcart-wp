<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
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
 *
 * The API keys and webhook secrets are stored encrypted with FluentCart's
 * `Helper::encryptKey()`, as FluentCart does for its Stripe and PayPal keys
 * (key from `FLUENT_CART_ENCRYPTION_KEY`, else WordPress's `LOGGED_IN_KEY`).
 */
final class VatlySettings extends BaseGatewaySettings
{
    public const METHOD_HANDLER = 'fluent_cart_payment_settings_vatly';

    /** Settings stored encrypted. */
    public const SECRET_FIELDS = ['test_api_key', 'test_webhook_secret', 'live_api_key', 'live_webhook_secret'];

    /** The option builds before 0.1.2-alpha stored everything in, in plain text. */
    public const LEGACY_OPTION = 'fluent_cart_vatly_settings';

    /** @var string */
    public $methodHandler = self::METHOD_HANDLER;

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function encryptSecrets(array $settings): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (! empty($settings[$field]) && is_string($settings[$field])) {
                $settings[$field] = Helper::encryptKey(trim($settings[$field]));
            }
        }

        return $settings;
    }

    /**
     * The plain value of a stored secret. A value saved before encryption is
     * returned as is.
     *
     * @param mixed $stored
     */
    public static function revealSecret($stored): string
    {
        $stored = (string) $stored;
        if ($stored === '' || ! Helper::isValueEncrypted($stored)) {
            return $stored;
        }

        return (string) Helper::decryptKey($stored);
    }

    /**
     * Move settings from the plain-text option older builds used into
     * FluentCart's store, encrypted, and delete the old option. Does nothing
     * once the old option is gone, or when settings were already saved here.
     */
    public static function migrateLegacyOption(): void
    {
        $legacy = get_option(self::LEGACY_OPTION, null);
        if (! is_array($legacy) || ! function_exists('fluent_cart_update_option')) {
            return;
        }

        $current = fluent_cart_get_option(self::METHOD_HANDLER, []);
        if (! is_array($current) || empty(array_filter(array_intersect_key($current, array_flip(self::SECRET_FIELDS))))) {
            $migrated = array_intersect_key($legacy, array_flip(self::SECRET_FIELDS));
            $migrated['is_active'] = empty($legacy['is_active']) || $legacy['is_active'] === 'no' ? 'no' : 'yes';
            fluent_cart_update_option(self::METHOD_HANDLER, self::encryptSecrets(array_merge(static::getDefaults(), $migrated)));
        }

        delete_option(self::LEGACY_OPTION);
    }

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
