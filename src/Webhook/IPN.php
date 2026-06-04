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
        $payload = file_get_contents('php://input') ?: '';
        $signature = $this->signatureHeader();

        try {
            $this->plugin->webhookProcessor()->handle($payload, $signature);
        } catch (InvalidWebhookSignatureException) {
            $this->respond(401, ['error' => 'invalid_signature']);
            return;
        } catch (\Throwable $e) {
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
