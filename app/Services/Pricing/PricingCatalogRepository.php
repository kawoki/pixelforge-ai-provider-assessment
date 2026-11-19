<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PixelforgeClient;
use App\Data\PricingCatalogData;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PricingPayloadMapper;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pixelforge's current rates and token rules (ADR-0004).
 *
 * Read from GET /v1/pricing ("rates can change — read them rather than hardcoding"), cached for
 * an hour by CachedPixelforgeClient. If Pixelforge is unreachable, falls back to the copy in config.
 * That only affects estimates: actual costs always use the rates stored on each generation.
 */
final readonly class PricingCatalogRepository
{
    private const string UNAVAILABLE = 'pixelforge-pricing-unavailable';

    /**
     * @param  array<string, mixed>  $fallback  same shape as GET /v1/pricing
     */
    public function __construct(
        private PixelforgeClient $client,
        private PricingPayloadMapper $payloads,
        private Cache $cache,
        #[Config('pixelforge.pricing.fallback')] private array $fallback,
    ) {}

    public function current(): PricingCatalogData
    {
        if ($this->cache->has(self::UNAVAILABLE)) {
            return $this->payloads->map($this->fallback);
        }

        try {
            return $this->client->getPricing();
        } catch (PixelforgeException $e) {
            // Don't ask again on every keystroke's estimate while Pixelforge is down.
            $this->cache->put(self::UNAVAILABLE, true, 60);
            Log::warning('Pixelforge pricing unavailable; estimating with config fallback.', ['error' => $e->getMessage()]);

            return $this->payloads->map($this->fallback);
        }
    }
}
