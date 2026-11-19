<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Exceptions\Pixelforge\PixelforgeNotConfiguredException;
use App\Exceptions\Webhooks\InvalidWebhookSignatureException;
use Illuminate\Container\Attributes\Config;

/**
 * Verifies `Pixelforge-Signature: t=<unix>,v1=<hex>` (PIXELFORGE_API.md → Signature verification, ADR-0007):
 *
 *   v1 = hex(HMAC_SHA256(webhook secret, "<t>.<raw request body>"))
 *
 * Compared in constant time, against the raw body before any JSON decoding, and rejected
 * when `t` is more than the tolerance (300 s) away from now, which stops replays.
 */
final readonly class WebhookSignatureVerifier
{
    public function __construct(
        #[Config('pixelforge.webhook.secret')] private ?string $secret,
        #[Config('pixelforge.webhook.tolerance_seconds')] private int $toleranceSeconds,
    ) {}

    /**
     * @throws InvalidWebhookSignatureException
     */
    public function verify(string $rawBody, ?string $header): void
    {
        $secret = $this->secret ?: throw PixelforgeNotConfiguredException::missing('PIXELFORGE_WEBHOOK_SECRET');
        ['timestamp' => $timestamp, 'signatures' => $signatures] = $this->parse((string) $header);

        $this->assertFresh($timestamp);
        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidWebhookSignatureException('Signature does not match.');
    }

    /**
     * Several v1 entries are accepted, so a sender rotating secrets keeps working.
     *
     * @return array{timestamp: int, signatures: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $timestamp = $key === 't' && ctype_digit($value) ? (int) $value : $timestamp;
            $signatures = $key === 'v1' && $value !== '' ? [...$signatures, $value] : $signatures;
        }

        return $timestamp === null || $signatures === []
            ? throw new InvalidWebhookSignatureException('Missing or malformed Pixelforge-Signature header.')
            : ['timestamp' => $timestamp, 'signatures' => $signatures];
    }

    private function assertFresh(int $timestamp): void
    {
        if (abs(now()->getTimestamp() - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignatureException('Signature timestamp is outside the tolerance window.');
        }
    }
}
