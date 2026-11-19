<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Webhooks\ProcessWebhookEventAction;
use App\Exceptions\Generations\GenerationNotYetKnownException;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes a stored webhook event outside the request, so Pixelforge gets its 2xx fast (ADR-0007).
 * An event for a generation we don't know yet is retried a few times over ~4 minutes;
 * after that, reconciliation (ADR-0010) or a dashboard resend picks it up.
 */
final class ProcessWebhookEventJob implements ShouldQueue
{
    use Queueable;

    /** @var list<int> */
    private const array RETRY_DELAYS = [5, 15, 30, 60, 120];

    public int $tries = 6;

    public function __construct(public readonly WebhookEvent $event)
    {
        $this->afterCommit();
    }

    public function handle(ProcessWebhookEventAction $process): void
    {
        try {
            $process->execute($this->event);
        } catch (GenerationNotYetKnownException $e) {
            $this->recordError($e);
            $this->release(self::RETRY_DELAYS[min($this->attempts(), count(self::RETRY_DELAYS)) - 1]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordError($exception);
    }

    private function recordError(?Throwable $exception): void
    {
        $this->event->forceFill(['processing_error' => $exception?->getMessage() ?? 'Processing failed.'])->save();
    }
}
