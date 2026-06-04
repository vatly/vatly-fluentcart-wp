<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Config;

use Vatly\Fluent\Contracts\ConfigurationInterface;

/**
 * Reads gateway settings persisted by FluentCart's settings UI and exposes them
 * through the framework-agnostic ConfigurationInterface used by vatly-fluent-php.
 *
 * Implements `isTestmode()` explicitly (rather than via DerivesTestmodeFromApiKey)
 * because the gateway settings UI exposes a test/live mode toggle that some
 * merchants prefer to flip independently of the key prefix during onboarding.
 */
final class VatlyConfig implements ConfigurationInterface
{
    public const OPTION_KEY = 'fluent_cart_vatly_settings';

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public function getApiKey(): string
    {
        return $this->isTestmode()
            ? (string) $this->get('test_api_key')
            : (string) $this->get('live_api_key');
    }

    public function getApiUrl(): string
    {
        return (string) ($this->get('api_url') ?: 'https://api.vatly.com');
    }

    public function getApiVersion(): string
    {
        return (string) ($this->get('api_version') ?: 'v1');
    }

    public function getWebhookSecret(): ?string
    {
        $secret = $this->isTestmode()
            ? $this->get('test_webhook_secret')
            : $this->get('live_webhook_secret');

        return $secret !== null && $secret !== '' ? (string) $secret : null;
    }

    public function isTestmode(): bool
    {
        return ($this->get('payment_mode') ?: 'test') === 'test';
    }

    public function getDefaultRedirectUrlSuccess(): string
    {
        return (string) ($this->get('redirect_url_success') ?: home_url('/'));
    }

    public function getDefaultRedirectUrlCanceled(): string
    {
        return (string) ($this->get('redirect_url_canceled') ?: home_url('/'));
    }

    public function webhookUrl(): string
    {
        return add_query_arg(
            'action',
            'fluent_cart_vatly_webhook',
            admin_url('admin-ajax.php')
        );
    }

    public function isConfigured(): bool
    {
        return $this->getApiKey() !== '' && $this->getWebhookSecret() !== null;
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->get('is_active') ?? false);
    }

    /**
     * @return mixed
     */
    private function get(string $key)
    {
        $this->settings ??= (array) get_option(self::OPTION_KEY, []);

        return $this->settings[$key] ?? null;
    }
}
