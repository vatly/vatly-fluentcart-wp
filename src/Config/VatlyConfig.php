<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Config;

use FluentCart\Api\StoreSettings;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\FluentCart\PaymentMethod\VatlySettings;

/**
 * Reads the gateway settings FluentCart's settings screen saved (see
 * {@see VatlySettings}) and exposes them through the framework-agnostic
 * ConfigurationInterface used by vatly-fluent-php.
 *
 * Test or live follows FluentCart's store mode, like FluentCart's built-in
 * gateways, so new checkouts use the key for the mode FluentCart stamps on the
 * order. {@see self::withTestmode()} pins a mode for existing records.
 */
final class VatlyConfig implements ConfigurationInterface
{

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    /**
     * When set, overrides the settings-derived mode. Used by
     * {@see \Vatly\FluentCart\Plugin::vatlyForTestmode()} to pin a client to
     * the mode a given record was created in, so the matching API key/secret
     * are selected regardless of the current settings toggle.
     */
    private ?bool $testmodeOverride = null;

    /**
     * Return a copy pinned to an explicit test/live mode. Immutable — the
     * shared, settings-backed instance is left untouched.
     */
    public function withTestmode(bool $testmode): self
    {
        $clone = clone $this;
        $clone->testmodeOverride = $testmode;

        return $clone;
    }

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
        if ($this->testmodeOverride !== null) {
            return $this->testmodeOverride;
        }

        return (new StoreSettings())->get('order_mode') !== 'live';
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
        return $this->get('is_active') === 'yes';
    }

    /**
     * @return mixed
     */
    private function get(string $key)
    {
        $this->settings ??= (array) fluent_cart_get_option(VatlySettings::METHOD_HANDLER, []);

        return $this->settings[$key] ?? null;
    }
}
