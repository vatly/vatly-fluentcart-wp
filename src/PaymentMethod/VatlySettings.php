<?php

declare(strict_types=1);

namespace Vatly\FluentCart\PaymentMethod;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentSettings;
use Vatly\FluentCart\Config\VatlyConfig;

/**
 * Settings fields rendered by FluentCart on the gateway configuration screen.
 *
 * Values are persisted by FluentCart under the option key declared in
 * {@see VatlyConfig::OPTION_KEY}.
 */
final class VatlySettings extends AbstractPaymentSettings
{
    public string $optionKey = VatlyConfig::OPTION_KEY;

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
