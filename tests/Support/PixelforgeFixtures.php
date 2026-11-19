<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\TokenRulesData;
use Brick\Math\BigDecimal;

/**
 * Payloads and values copied from PIXELFORGE_API.md, so tests pin behaviour to the docs.
 */
final class PixelforgeFixtures
{
    public static function tokenRules(): TokenRulesData
    {
        $tokens = self::pricingPayload()['tokens'];

        return new TokenRulesData(4, BigDecimal::of('0.15'), 765, $tokens['output'], $tokens['reasoning']);
    }

    /**
     * GET /v1/pricing response, verbatim from the docs.
     *
     * @return array<string, mixed>
     */
    public static function pricingPayload(): array
    {
        return json_decode(<<<'JSON'
            {"object": "pricing", "currency": "usd", "unit": "per_1m_tokens",
             "rates": {"input_text_tokens": "5.00", "input_image_tokens": "10.00", "output_tokens": "40.00", "reasoning_tokens": "12.50"},
             "tokens": {"characters_per_text_token": 4, "text_token_variance": 0.15, "per_reference_image": 765,
               "output": {"low": {"1024x1024": 280, "1536x1024": 420, "1024x1536": 420},
                          "medium": {"1024x1024": 1100, "1536x1024": 1650, "1024x1536": 1650},
                          "high": {"1024x1024": 4300, "1536x1024": 6450, "1024x1536": 6450}},
               "reasoning": {"low": {"min": 0, "max": 500}, "medium": {"min": 200, "max": 2000}, "high": {"min": 1000, "max": 8000}}}}
            JSON, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A webhook event envelope whose `data.object` is the docs' generation object (PIXELFORGE_API.md → Webhooks).
     *
     * @param  array<string, mixed>  $generation  overrides for the generation object
     * @return array<string, mixed>
     */
    public static function webhookEvent(string $type, array $generation = [], ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(8)),
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => self::generationPayload($generation)],
        ];
    }

    /**
     * The docs' generation object as it looks while queued or processing (no usage, image or error yet).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function inFlightGeneration(string $status, array $overrides = []): array
    {
        return [
            'status' => $status, 'usage' => null, 'image_url' => null, 'image_url_expires_at' => null,
            'completed_at' => null, 'started_at' => $status === 'processing' ? '2026-11-04T14:03:13Z' : null, ...$overrides,
        ];
    }

    /**
     * `Pixelforge-Signature` header value, computed exactly as the docs describe.
     */
    public static function signature(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    /**
     * The docs' succeeded generation object, with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function generationPayload(array $overrides = []): array
    {
        return array_replace(json_decode(<<<'JSON'
            {"id": "gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K", "object": "generation", "status": "succeeded",
             "prompt": "A lighthouse on a cliff at dawn, oil painting", "size": "1536x1024", "quality": "high",
             "reference_images": ["https://example.com/style.png"], "metadata": {"client_reference": "ref-1"},
             "image_url": "https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc",
             "image_url_expires_at": "2026-11-05T14:03:29Z",
             "usage": {"input_text_tokens": 12, "input_image_tokens": 765, "output_tokens": 6450, "reasoning_tokens": 3184, "total_tokens": 10411},
             "rates": {"input_text_tokens": "5.00", "input_image_tokens": "10.00", "output_tokens": "40.00", "reasoning_tokens": "12.50"},
             "error": null, "created_at": "2026-11-04T14:03:11Z", "started_at": "2026-11-04T14:03:13Z", "completed_at": "2026-11-04T14:03:29Z"}
            JSON, true, flags: JSON_THROW_ON_ERROR), $overrides);
    }
}
