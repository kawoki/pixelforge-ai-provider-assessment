<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BackgroundProcess;
use App\Services\Health\HeartbeatService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched every minute by the scheduler; when a worker runs it, the queue is alive (ADR-0015).
 * Unique, so a dead worker doesn't collect a pile of these.
 */
final class RecordQueueHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(HeartbeatService $heartbeats): void
    {
        $heartbeats->beat(BackgroundProcess::QueueWorker);
    }
}
