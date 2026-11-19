<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Data\BackgroundHealthData;
use App\Enums\BackgroundProcess;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Decides whether the scheduler and queue worker are running (ADR-0015).
 *
 * - Scheduler: its heartbeat is recent.
 * - Queue: no job has waited too long, and (when the scheduler is up to dispatch it)
 *   the heartbeat job ran recently. Jobs piling up is direct evidence even if the scheduler is down.
 */
final readonly class BackgroundHealthService
{
    private const string VERDICT = 'health:background';

    private const string FIRST_CHECK = 'health:first-check';

    /**
     * @param  array{stale_after_seconds: int, max_job_wait_seconds: int, startup_grace_seconds: int, cache_seconds: int}  $config
     */
    public function __construct(
        private HeartbeatService $heartbeats,
        private QueueFactory $queues,
        private Cache $cache,
        #[Config('monitoring.health')] private array $config,
    ) {}

    public function check(): BackgroundHealthData
    {
        /** @var array{scheduler: bool, queue: bool} $verdict */
        $verdict = $this->cache->remember(self::VERDICT, $this->config['cache_seconds'], fn (): array => $this->evaluate()->toArray());

        return BackgroundHealthData::fromArray($verdict);
    }

    public function forget(): void
    {
        $this->cache->forget(self::VERDICT);
    }

    private function evaluate(): BackgroundHealthData
    {
        $scheduler = $this->beatingRecently(BackgroundProcess::Scheduler);
        $queue = $this->jobsAreBeingTaken() && (! $scheduler || $this->beatingRecently(BackgroundProcess::QueueWorker));

        return new BackgroundHealthData($scheduler, $queue);
    }

    private function beatingRecently(BackgroundProcess $process): bool
    {
        $last = $this->heartbeats->lastBeat($process);

        return $last === null
            ? $this->withinStartupGrace()
            : $last->diffInSeconds(now()) <= $this->config['stale_after_seconds'];
    }

    private function jobsAreBeingTaken(): bool
    {
        $oldest = $this->queues->connection()->creationTimeOfOldestPendingJob();

        return $oldest === null || now()->getTimestamp() - (int) $oldest <= $this->config['max_job_wait_seconds'];
    }

    /**
     * Right after a deploy no process has beaten yet; give them time before calling it an outage.
     */
    private function withinStartupGrace(): bool
    {
        $first = (int) $this->cache->rememberForever(self::FIRST_CHECK, fn (): int => now()->getTimestamp());

        return now()->getTimestamp() - $first <= $this->config['startup_grace_seconds'];
    }
}
