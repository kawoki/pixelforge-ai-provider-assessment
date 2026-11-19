<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds generations whose outcome we may have missed (ADR-0010).
 */
final readonly class StaleGenerationFinder
{
    /** When we last heard anything about a generation. */
    private const string LAST_CONTACT = 'COALESCE(last_provider_event_at, submitted_at, created_at)';

    public function __construct(
        #[Config('pixelforge.reconciliation.stale_after_seconds')] private int $staleAfterSeconds,
        #[Config('pixelforge.reconciliation.lost_submission_after_minutes')] private int $lostAfterMinutes,
    ) {}

    /**
     * Accepted by Pixelforge, not finished here, and silent for a while: oldest first.
     *
     * @return Collection<int, Generation>
     */
    public function awaitingProvider(int $limit): Collection
    {
        return $this->inFlight()
            ->whereNotNull('provider_generation_id')
            ->whereRaw(self::LAST_CONTACT.' < ?', [now()->subSeconds($this->staleAfterSeconds)])
            ->orderByRaw(self::LAST_CONTACT)
            ->limit($limit)
            ->get();
    }

    /**
     * Never accepted, and older than any submission job could still be retrying.
     *
     * @return Collection<int, Generation>
     */
    public function lostSubmissions(int $limit): Collection
    {
        return $this->inFlight()
            ->whereNull('provider_generation_id')
            ->where('created_at', '<', now()->subMinutes($this->lostAfterMinutes))
            ->oldest()
            ->limit($limit)
            ->get();
    }

    /**
     * @return Builder<Generation>
     */
    private function inFlight(): Builder
    {
        return Generation::query()->whereIn('status', GenerationStatus::inFlight());
    }
}
