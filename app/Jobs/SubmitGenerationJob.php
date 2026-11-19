<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Generations\SubmitGenerationToProviderAction;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Models\Generation;
use App\Services\Generations\FailureMessages;
use App\Services\Generations\GenerationLifecycleService;
use App\Services\Generations\SubmissionRetryPolicy;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submits a generation to Pixelforge, retrying temporary failures (ADR-0005): waiting for a free
 * slot or an answer until the retry window closes, giving up early on repeated "overloaded"
 * refusals (SubmissionRetryPolicy). Retries are releases, not exceptions, so only unexpected
 * errors count towards $maxExceptions.
 *
 * Unique per generation while queued or retrying, so a requeue by reconciliation (ADR-0010)
 * never runs alongside a submission job that is still alive.
 */
final class SubmitGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    /** Seconds; longer than the retry window, shorter than the "lost submission" threshold. */
    public int $uniqueFor = 35 * 60;

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function __construct(public readonly Generation $generation)
    {
        $this->afterCommit();
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('pixelforge.submission.retry_until_minutes'));
    }

    public function handle(SubmitGenerationToProviderAction $submit, SubmissionRetryPolicy $retry): void
    {
        try {
            $submit->execute($this->generation);
        } catch (PixelforgeException $e) {
            $retry->exhausted($this->generation, $e)
                ? $this->giveUp($retry->maxAttemptsWhenUnavailable())
                : $this->release($retry->delaySeconds($e, $this->attempts()));
        }
    }

    /**
     * Pixelforge refused it every time and created nothing: tell the user now rather than
     * leave it "Queued" for the whole retry window. Not a job failure, so no admin alert.
     */
    private function giveUp(int $attempts): void
    {
        Log::warning('Gave up submitting a generation Pixelforge kept refusing.', ['generation' => $this->generation->id, 'attempts' => $attempts]);

        app(GenerationLifecycleService::class)->abandonSubmission(
            $this->generation,
            FailureMessages::PROVIDER_UNAVAILABLE_CODE,
            app(FailureMessages::class)->providerUnavailable($attempts),
        );
    }

    /**
     * The retry window closed (or something unexpected kept failing): give up, free of charge —
     * unless Pixelforge did accept it, in which case it is running and webhooks will finish it.
     */
    public function failed(?Throwable $exception): void
    {
        $messages = app(FailureMessages::class);

        app(GenerationLifecycleService::class)->abandonSubmission($this->generation, FailureMessages::PROVIDER_BUSY_CODE, $messages->providerBusy());
    }
}
