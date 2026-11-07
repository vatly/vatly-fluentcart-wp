<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use Vatly\FluentCart\Config\VatlyConfig;

/**
 * Settings fields rendered by FluentCart on the gateway configuration screen.
 *
 * FluentCart's BaseGatewaySettings keeps per-gateway settings in the fct_meta
 * table keyed by {@see $methodHandler}. This gateway instead keeps its settings
 * in its own WP option (see {@see VatlyConfig::OPTION_KEY}) so the same values
 * are readable through the framework-agnostic ConfigurationInterface that
 * vatly-fluent-php consumes. We therefore override the three abstract accessors
 * ({@see get()}, {@see getMode()}, {@see isActive()}) to read that option
 * directly rather than the fct_meta blob the base constructor loads.
 */
final class VatlySettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_' . VATLY_FLUENTCART_GATEWAY_SLUG;

    /**
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        return [
            'is_active'    => false,
            'payment_mode' => 'test',
        ];
    }

    /**
     * Read a single setting (or all of them when $key is empty) from this
     * gateway's own option store.
     *
     * @param string $key
     * @return mixed
     */
    public function get($key = '')
    {
        $all = (array) get_option(VatlyConfig::OPTION_KEY, []);

        // FluentCart's checkout (PaymentMethods::getActiveMethodInstance) and
        // settings UI expect `is_active` as the string 'yes'/'no'. Our option
        // stores it as a boolean (VatlyConfig reads it that way), so normalize
        // to FluentCart's convention on the way out.
        $all['is_active'] = empty($all['is_active']) ? 'no' : 'yes';

        if ($key === '') {
            return $all;
        }

        return $all[$key] ?? null;
    }

    public function getMode()
    {
        return $this->get('payment_mode') ?: 'test';
    }

    public function isActive()
    {
        return $this->get('is_active') === 'yes';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            'is_active' => [
                'type'  => 'checkbox',
                'label' => __('Enable Vatly', 'vatly-for-fluentcart'),
            ],
            'payment_mode' => [
                'type'    => 'radio_group',
                'label'   => __('Mode', 'vatly-for-fluentcart'),
                'options' => [
                    'test' => __('Test', 'vatly-for-fluentcart'),
                    'live' => __('Live', 'vatly-for-fluentcart'),
                ],
                'default' => 'test',
            ],
            'test_api_key' => [
                'type'        => 'text',
                'label'       => __('Test API Key', 'vatly-for-fluentcart'),
                'visible'     => ['payment_mode' => 'test'],
            ],
            'test_webhook_secret' => [
                'type'        => 'password',
                'label'       => __('Test Webhook Secret', 'vatly-for-fluentcart'),
                'description' => __('Used to verify the Vatly-Signature header on incoming webhook deliveries.', 'vatly-for-fluentcart'),
                'visible'     => ['payment_mode' => 'test'],
            ],
            'live_api_key' => [
                'type'        => 'text',
                'label'       => __('Live API Key', 'vatly-for-fluentcart'),
                'visible'     => ['payment_mode' => 'live'],
            ],
            'live_webhook_secret' => [
                'type'        => 'password',
                'label'       => __('Live Webhook Secret', 'vatly-for-fluentcart'),
                'visible'     => ['payment_mode' => 'live'],
            ],
            'webhook_url_display' => [
                'type'        => 'html',
                'label'       => __('Webhook URL', 'vatly-for-fluentcart'),
                'html'        => '<code>' . esc_html((new VatlyConfig())->webhookUrl()) . '</code>',
                'description' => __('Configure this URL in your Vatly dashboard under Webhooks.', 'vatly-for-fluentcart'),
            ],
        ];
    }
}
