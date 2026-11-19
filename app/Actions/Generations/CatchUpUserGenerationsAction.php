<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Contracts\PixelforgeClient;
use App\Enums\GenerationStatus;
use App\Exceptions\Generations\GenerationNotYetKnownException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Models\Generation;
use App\Models\User;
use App\Services\Health\BackgroundHealthService;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Degraded mode (ADR-0015): while the queue worker or scheduler is down, the History page's
 * polling asks us to move this user's generations forward ourselves, so nobody is left staring
 * at "Queued" and no finished image goes unbilled:
 *
 * - not yet accepted → submit now (same Idempotency-Key, so never twice)
 * - accepted         → fetch from Pixelforge and apply (same path as webhooks, so charged once)
 *
 * Does nothing while background processing is healthy. Bounded per generation and globally,
 * because every call spends the shared 60 requests/min account budget.
 */
final readonly class CatchUpUserGenerationsAction
{
    /**
     * @param  array{max_per_request: int, per_generation_seconds: int, max_requests_per_minute: int}  $config
     */
    public function __construct(
        private BackgroundHealthService $health,
        private SubmitGenerationToProviderAction $submit,
        private PixelforgeClient $pixelforge,
        private ApplyProviderUpdateAction $applyUpdate,
        private Cache $cache,
        #[Config('pixelforge.fallback')] private array $config,
    ) {}

    /**
     * @return int how many generations were checked with Pixelforge
     */
    public function execute(User $user): int
    {
        if (! $this->health->check()->degraded()) {
            return 0;
        }

        $checked = 0;

        foreach ($this->inFlight($user) as $generation) {
            if ($this->claim($generation)) {
                $this->catchUp($generation);
                $checked++;
            }
        }

        return $checked;
    }

    /**
     * @return Collection<int, Generation>
     */
    private function inFlight(User $user): Collection
    {
        return $user->generations()->whereIn('status', GenerationStatus::inFlight())->oldest()->limit($this->config['max_per_request'])->get();
    }

    /**
     * At most once per generation per window (however many tabs poll), within a global budget.
     */
    private function claim(Generation $generation): bool
    {
        return $this->cache->add("fallback:{$generation->id}", true, $this->config['per_generation_seconds'])
            && RateLimiter::attempt('pixelforge-fallback', $this->config['max_requests_per_minute'], fn (): bool => true);
    }

    private function catchUp(Generation $generation): void
    {
        try {
            $generation->provider_generation_id === null
                ? $this->submit->execute($generation)
                : $this->applyUpdate->execute($this->pixelforge->getGeneration($generation->provider_generation_id));
        } catch (PixelforgeException|GenerationNotYetKnownException $e) {
            Log::info('Fallback check did not complete; will retry on the next poll.', ['generation' => $generation->id, 'error' => $e->getMessage()]);
        }
    }
}
