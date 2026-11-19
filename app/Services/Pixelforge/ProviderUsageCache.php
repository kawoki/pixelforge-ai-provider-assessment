<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Names the cached copies of `GET /v1/usage` (ADR-0012), and throws them all away at once when
 * one of our generations succeeds. Without that, the spend report would compare our fresh charge
 * with Pixelforge figures fetched before it and flag a mismatch that isn't real.
 *
 * Every entry name carries the current version; invalidating just starts a new version, so
 * ranges of any shape go stale together and the old entries simply expire.
 */
final readonly class ProviderUsageCache
{
    private const string VERSION_ENTRY = 'pixelforge-usage-version';

    public function __construct(private Cache $cache) {}

    public function entry(CarbonImmutable $startDate, CarbonImmutable $endDate): string
    {
        $version = $this->cache->get(self::VERSION_ENTRY, 'initial');

        return "pixelforge-usage:{$version}:{$startDate->toDateString()}:{$endDate->toDateString()}";
    }

    public function invalidate(): void
    {
        $this->cache->forever(self::VERSION_ENTRY, (string) Str::ulid());
    }
}
