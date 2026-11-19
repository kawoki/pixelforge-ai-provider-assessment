<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Events\GenerationUpdated;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the owner's browser a generation changed (ADR-0009).
 *
 * Only after the change is committed (never announce what could still roll back), and a
 * WebSocket outage never breaks the change itself: the page notices it is no longer
 * connected and falls back to polling.
 */
final readonly class GenerationUpdatePublisher
{
    public function publish(Generation $generation): void
    {
        $event = new GenerationUpdated($generation->user_id, $generation->id, $generation->status->value);

        DB::afterCommit(fn () => $this->push($event));
    }

    private function push(GenerationUpdated $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Could not push a live update; browsers fall back to polling.', ['generation' => $event->generationId, 'error' => $e->getMessage()]);
        }
    }
}
