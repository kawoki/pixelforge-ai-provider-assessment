<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Enums\BackgroundProcess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Background processes prove they are alive by beating every minute (ADR-0015):
 * the scheduler directly, the queue worker by running a tiny scheduled job.
 */
final readonly class HeartbeatService
{
    public function __construct(private Cache $cache) {}

    public function beat(BackgroundProcess $process): void
    {
        $this->cache->forever($this->key($process), now()->getTimestamp());
    }

    public function lastBeat(BackgroundProcess $process): ?CarbonImmutable
    {
        $timestamp = $this->cache->get($this->key($process));

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    private function key(BackgroundProcess $process): string
    {
        return "heartbeat:{$process->value}";
    }
}
