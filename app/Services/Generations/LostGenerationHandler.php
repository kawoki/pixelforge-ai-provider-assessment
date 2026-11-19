<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\AlertType;
use App\Models\Generation;
use App\Services\Alerts\AdminAlertService;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Pixelforge accepted a generation but now answers not_found for it (ADR-0010). Once is likely a
 * hiccup; several polls in a row (~10 min) mean it's gone, or we're using a different API key.
 * Then it is failed for free, so the user's held credits are released, and an admin is told.
 */
final readonly class LostGenerationHandler
{
    public function __construct(
        private GenerationLifecycleService $lifecycle,
        private FailureMessages $messages,
        private AdminAlertService $alerts,
        private Cache $cache,
        #[Config('pixelforge.reconciliation.give_up_after_not_found')] private int $giveUpAfter,
    ) {}

    public function recordNotFound(Generation $generation): void
    {
        $counter = "reconcile-not-found:{$generation->id}";
        $this->cache->add($counter, 0, now()->addDay());

        if ((int) $this->cache->increment($counter) < $this->giveUpAfter) {
            $this->deferNextPoll($generation);

            return;
        }

        $this->giveUp($generation);
    }

    private function giveUp(Generation $generation): void
    {
        $this->lifecycle->fail($generation, 'not_found_at_provider', $this->messages->lostAtProvider());
        $this->alerts->raise(
            AlertType::GenerationLostAtProvider,
            "Generation {$generation->id} (Pixelforge {$generation->provider_generation_id}) was not found at Pixelforge {$this->giveUpAfter} times in a row.",
        );
    }

    /**
     * Count the attempt as contact, so this generation waits its turn instead of starving the others.
     */
    private function deferNextPoll(Generation $generation): void
    {
        $generation->forceFill(['last_provider_event_at' => now()])->save();
    }
}
