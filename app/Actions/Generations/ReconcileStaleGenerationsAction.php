<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Contracts\PixelforgeClient;
use App\Data\ReconciliationResultData;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Alerts\WebhookDeliveryMonitor;
use App\Services\Generations\LostGenerationHandler;
use App\Services\Generations\StaleGenerationFinder;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Log;

/**
 * The polling fallback for lost webhooks (ADR-0010; PIXELFORGE_API.md: "keep polling as a fallback").
 *
 * Generations Pixelforge accepted but never told us about are fetched and applied through the
 * same path as webhooks, so they settle exactly once. Submissions whose job was lost are requeued
 * (safe: same Idempotency-Key). Stops early when Pixelforge says we're asking too often.
 */
final readonly class ReconcileStaleGenerationsAction
{
    public function __construct(
        private StaleGenerationFinder $finder,
        private PixelforgeClient $pixelforge,
        private ApplyProviderUpdateAction $applyUpdate,
        private WebhookDeliveryMonitor $monitor,
        private LostGenerationHandler $lost,
        #[Config('pixelforge.reconciliation.batch_size')] private int $batchSize,
    ) {}

    public function execute(): ReconciliationResultData
    {
        $requeued = $this->requeueLostSubmissions();
        $polled = $errors = 0;

        foreach ($this->finder->awaitingProvider($this->batchSize) as $generation) {
            $outcome = $this->poll($generation);

            if ($outcome === 'rate_limited') {
                return new ReconciliationResultData($polled, $errors, $requeued, stoppedByRateLimit: true);
            }

            $polled++;
            $errors += $outcome === 'error' ? 1 : 0;
        }

        return new ReconciliationResultData($polled, $errors, $requeued);
    }

    /**
     * @return 'ok'|'error'|'rate_limited'
     */
    private function poll(Generation $generation): string
    {
        try {
            $this->applyAndWatch($generation);
        } catch (GenerationNotFoundException) {
            $this->lost->recordNotFound($generation);

            return 'error';
        } catch (PixelforgeException $e) {
            Log::warning('Could not reconcile generation.', ['generation' => $generation->id, 'error' => $e->getMessage()]);

            return $e->isRetryable() ? 'rate_limited' : 'error';
        }

        return 'ok';
    }

    /**
     * Apply what Pixelforge reports; if that finished the generation, a webhook should have done it first.
     */
    private function applyAndWatch(Generation $generation): void
    {
        $wasFinished = $generation->status->isTerminal();
        $updated = $this->applyUpdate->execute($this->pixelforge->getGeneration((string) $generation->provider_generation_id));

        if (! $wasFinished && $updated->status->isTerminal()) {
            $this->monitor->recordFinishedByPolling($updated);
        }
    }

    private function requeueLostSubmissions(): int
    {
        $lost = $this->finder->lostSubmissions($this->batchSize);

        foreach ($lost as $generation) {
            Log::warning('Requeueing a submission whose job was lost.', ['generation' => $generation->id]);
            SubmitGenerationJob::dispatch($generation);
        }

        return $lost->count();
    }
}
