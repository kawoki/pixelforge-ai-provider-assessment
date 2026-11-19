<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Contracts\PixelforgeClient;
use App\Data\GenerationRequestData;
use App\Data\PricingCatalogData;
use App\Data\ProviderGenerationData;
use App\Data\ProviderUsageData;
use App\Services\Pricing\PricingDriftMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Caches Pixelforge responses that don't change, so we don't spend the account's shared
 * 60 requests/min on them:
 *
 * - pricing: 1 h (it changes rarely; each generation is billed at its own fixed rates anyway)
 * - usage for days that are over: 1 h (final); a range including today: 5 min (it grows)
 *
 * Generations are never cached: we only fetch ones that haven't finished, and those change.
 * Only plain arrays are cached (Laravel refuses to unserialize objects from the cache).
 */
final readonly class CachedPixelforgeClient implements PixelforgeClient
{
    /**
     * @param  array{pricing_seconds: int, usage_past_seconds: int, usage_today_seconds: int}  $ttl
     */
    public function __construct(
        private PixelforgeHttpClient $pixelforge,
        private PricingPayloadMapper $pricing,
        private PricingDriftMonitor $drift,
        private Cache $cache,
        #[Config('pixelforge.cache')] private array $ttl,
    ) {}

    public function createGeneration(GenerationRequestData $request, string $clientReference): ProviderGenerationData
    {
        return $this->pixelforge->createGeneration($request, $clientReference);
    }

    public function getGeneration(string $providerGenerationId): ProviderGenerationData
    {
        return $this->pixelforge->getGeneration($providerGenerationId);
    }

    public function getPricing(): PricingCatalogData
    {
        $published = $this->cache->remember('pixelforge-pricing', $this->ttl['pricing_seconds'], function (): array {
            $live = $this->pixelforge->getPricing();
            $this->drift->check($live); // once per hour, when the cache refreshes

            return $live->toArray();
        });

        return $this->pricing->map($published);
    }

    public function getUsage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsageData
    {
        $includesToday = $endDate->toDateString() >= now('UTC')->toDateString();
        $entry = "pixelforge-usage:{$startDate->toDateString()}:{$endDate->toDateString()}";

        /** @var list<array{date: string, generations: int, cost: string}> $days */
        $days = $this->cache->remember($entry, $this->ttl[$includesToday ? 'usage_today_seconds' : 'usage_past_seconds'], fn (): array => $this->pixelforge->getUsage($startDate, $endDate)->toArray());

        return ProviderUsageData::fromArray($days);
    }
}
