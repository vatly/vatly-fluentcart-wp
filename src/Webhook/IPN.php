<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Webhook;

use Vatly\Fluent\Exceptions\InvalidWebhookSignatureException;
use Vatly\Fluent\Webhooks\SignatureVerifier;
use Vatly\FluentCart\Plugin;

/**
 * Inbound Vatly webhook handler.
 *
 * Hooked on admin-ajax (wp_ajax_(nopriv_)?fluent_cart_vatly_webhook) to match
 * the convention from FluentCart's Paddle gateway, which keeps webhook URLs
 * short and avoids the REST namespace resolution overhead.
 */
final class IPN
{
    public function __construct(private Plugin $plugin) {}

    public function handle(): void
    {
        // Without a secret the signature check would accept an HMAC made with
        // an empty key, which anyone can compute. Refuse instead; Vatly retries.
        if ($this->plugin->config()->getWebhookSecret() === null) {
            $this->respond(503, ['error' => 'webhook_secret_not_configured']);
            return;
        }

        // Building the Vatly client throws without an API key, which used to
        // surface as a bare 500 on every call. Say what is missing instead.
        if ($this->plugin->config()->getApiKey() === '') {
            $this->respond(503, ['error' => 'api_key_not_configured']);
            return;
        }

        $payload = file_get_contents('php://input') ?: '';
        $signature = $this->signatureHeader();

        try {
            $processor = $this->plugin->webhookProcessor();
        } catch (\Throwable) {
            // The client rejects a malformed key the same way. Its message
            // repeats the key, so it is not logged.
            error_log('[vatly-for-fluentcart] webhook refused: the Vatly API key in the settings is not valid.');
            $this->respond(503, ['error' => 'api_key_invalid']);
            return;
        }

        try {
            $processor->handle($payload, $signature);
        } catch (InvalidWebhookSignatureException) {
            $this->respond(401, ['error' => 'invalid_signature']);
            return;
        } catch (\Throwable $e) {
            // A reaction inside the processor found no subscription here (yet).
            // That is not a failure of this site, so it isn't a 500; Vatly
            // retries any non-2xx, so the webhook is still redelivered later.
            if ($e instanceof SubscriptionNotFoundException) {
                error_log('[vatly-for-fluentcart] webhook refused: ' . $e->getMessage());
                $this->respond(404, ['error' => 'subscription_not_found']);
                return;
            }

            error_log('[vatly-for-fluentcart] webhook processing failed: ' . $e->getMessage());
            $this->respond(500, ['error' => 'processing_failed']);
            return;
        }

        $this->respond(200, ['ok' => true]);
    }

    private function signatureHeader(): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper(SignatureVerifier::SIGNATURE_HEADER_NAME));

        if (! isset($_SERVER[$key])) {
            return '';
        }

        // Vatly's signature header has the shape `t=<unix_seconds>,v1=<hex>`
        // (all ASCII), so sanitize_text_field cannot mangle it. wp_unslash
        // undoes magic-quotes-style escaping that legacy server configs may
        // inject; the verifier still rejects anything malformed.
        return sanitize_text_field(wp_unslash($_SERVER[$key]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): void
    {
        status_header($status);
        wp_send_json($body, $status);
    }
}
