<?php

declare(strict_types=1);

namespace Vatly\FluentCart\Tests\Unit;

use Vatly\Fluent\Exceptions\InvalidWebhookSignatureException;
use Vatly\Fluent\Webhooks\SignatureVerifier;
use Vatly\FluentCart\Tests\TestCase;

/**
 * Roundtrip the upstream {@see SignatureVerifier} so a header-shape
 * regression in vatly-fluent-php (or in our `IPN::signatureHeader`
 * sanitization) gets caught here rather than in production.
 *
 * Vatly's signature header format is `t=<unix_seconds>,v1=<hex_hmac_sha256>`
 * with HMAC input `"{$t}.{$rawBody}"`.
 *
 * @coversNothing
 */
class WebhookSignatureRoundTripTest extends TestCase
{
    public function test_correctly_signed_payload_verifies(): void
    {
        $secret    = 'a-real-secret-32-bytes-long-OKKK';
        $payload   = '{"id":"evt_1","resource":"subscription","eventName":"subscription.started"}';
        $t         = time();
        $signature = sprintf('t=%d,v1=%s', $t, hash_hmac('sha256', $t . '.' . $payload, $secret));

        (new SignatureVerifier())->verify($signature, $payload, $secret);

        // No exception means pass. Add an assertion so the test isn't risky.
        $this->assertTrue(true);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload   = '{"id":"evt_1"}';
        $signature = 't=' . time() . ',v1=' . hash_hmac('sha256', 'wrong-payload', 'secret');

        $this->expectException(InvalidWebhookSignatureException::class);

        (new SignatureVerifier())->verify($signature, $payload, 'secret');
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->expectException(InvalidWebhookSignatureException::class);

        (new SignatureVerifier())->verify('', '{}', 'secret');
    }

    public function test_tampered_timestamp_invalidates_signature(): void
    {
        $secret  = 'secret';
        $payload = '{"id":"evt_1"}';
        $t       = time();

        // Compute the HMAC for one timestamp, then ship a different one.
        $hmac      = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $signature = sprintf('t=%d,v1=%s', $t + 100, $hmac);

        $this->expectException(InvalidWebhookSignatureException::class);

        (new SignatureVerifier())->verify($signature, $payload, $secret);
    }
}
