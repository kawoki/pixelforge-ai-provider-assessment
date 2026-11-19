<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Contracts\PixelforgeClient;
use App\Data\GenerationRequestData;
use App\Data\ProviderGenerationData;
use App\Enums\AlertType;
use App\Exceptions\Pixelforge\DailyLimitExceededException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\ProviderAuthException;
use App\Models\Generation;
use App\Services\Alerts\AdminAlertService;
use App\Services\Generations\FailureMessages;
use App\Services\Generations\GenerationLifecycleService;
use Illuminate\Support\Facades\Log;

/**
 * Send one pending generation to Pixelforge (ADR-0005). One attempt per call:
 *
 * - accepted     → remember the provider id and the rates it will be billed at; status queued
 * - rejected     → failed with a readable reason; never charged
 * - retryable    → the exception is rethrown so the job can retry later with the same Idempotency-Key
 */
final readonly class SubmitGenerationToProviderAction
{
    public function __construct(
        private PixelforgeClient $pixelforge,
        private GenerationLifecycleService $lifecycle,
        private FailureMessages $messages,
        private AdminAlertService $alerts,
    ) {}

    /**
     * @throws PixelforgeException when the failure is retryable
     */
    public function execute(Generation $generation): void
    {
        if ($this->alreadySubmitted($generation)) {
            return;
        }

        $generation->increment('submission_attempts');

        try {
            $provider = $this->pixelforge->createGeneration($this->requestFor($generation), $generation->client_reference);
        } catch (PixelforgeException $e) {
            $this->rejectOrRethrow($generation, $e);

            return;
        }

        $this->accept($generation, $provider);
    }

    /**
     * A webhook (or an earlier attempt) may already have linked the provider generation.
     */
    private function alreadySubmitted(Generation $generation): bool
    {
        return $generation->provider_generation_id !== null || $generation->status->isTerminal();
    }

    private function requestFor(Generation $generation): GenerationRequestData
    {
        return new GenerationRequestData($generation->prompt, $generation->size, $generation->quality, $generation->reference_image_urls);
    }

    private function accept(Generation $generation, ProviderGenerationData $provider): void
    {
        $snapshot = $generation->rateCardSnapshot();
        $rateCard = $provider->rates === null ? $snapshot : $snapshot->withRates($provider->rates);

        $this->lifecycle->recordAcceptance($generation, $provider, $rateCard);
    }

    private function rejectOrRethrow(Generation $generation, PixelforgeException $e): void
    {
        if ($e->isRetryable()) {
            throw $e;
        }

        $this->report($generation, $e);
        $this->alertIfEveryoneIsAffected($e);
        $this->lifecycle->fail($generation, $e->providerCode() ?? 'submission_rejected', $this->messages->forRejectedSubmission($e));
    }

    /**
     * A bad API key or an exhausted daily quota fails every user's generations, not just this one.
     */
    private function alertIfEveryoneIsAffected(PixelforgeException $e): void
    {
        match (true) {
            $e instanceof ProviderAuthException => $this->alerts->raise(AlertType::ProviderKeyRejected, 'Pixelforge answered invalid_api_key to a generation request.'),
            $e instanceof DailyLimitExceededException => $this->alerts->raise(AlertType::DailyLimitReached, 'Pixelforge answered daily_limit_exceeded to a generation request.'),
            default => null,
        };
    }

    /**
     * Rejections caused by the user's input are normal; anything else is our problem.
     */
    private function report(Generation $generation, PixelforgeException $e): void
    {
        $context = ['generation' => $generation->id, 'code' => $e->providerCode(), 'error' => $e->getMessage()];

        match (true) {
            $e instanceof ProviderAuthException => Log::critical('Pixelforge rejected our API key.', $context),
            in_array($e->providerCode(), ['validation_failed', 'content_policy_violation', 'daily_limit_exceeded'], true) => Log::info('Pixelforge rejected a generation.', $context),
            default => Log::error('Pixelforge rejected a generation unexpectedly.', $context),
        };
    }
}
