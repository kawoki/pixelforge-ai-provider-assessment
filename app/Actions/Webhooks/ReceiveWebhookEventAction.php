<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Data\WebhookEnvelopeData;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookEnvelopeMapper;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Store a verified webhook event exactly once, then process it in the background (ADR-0007).
 *
 * The unique `provider_event_id` turns redeliveries into no-ops. A redelivery of an event we
 * stored but could not process yet (e.g. dashboard "resend") is processed again, which is safe.
 */
final readonly class ReceiveWebhookEventAction
{
    public function __construct(private WebhookEnvelopeMapper $envelopes) {}

    /**
     * @throws UnexpectedProviderResponseException when the body is not a Pixelforge event
     */
    public function execute(string $rawBody): void
    {
        $envelope = $this->envelopes->fromRawBody($rawBody);
        $event = $this->storeOnce($envelope) ?? $this->unprocessedDuplicate($envelope);

        if ($event !== null) {
            ProcessWebhookEventJob::dispatch($event);
        }
    }

    private function storeOnce(WebhookEnvelopeData $envelope): ?WebhookEvent
    {
        try {
            // Own transaction (a savepoint when nested), so a duplicate never poisons an outer one.
            return DB::transaction(fn (): WebhookEvent => WebhookEvent::query()->create([
                'provider_event_id' => $envelope->eventId,
                'type' => $envelope->type,
                'provider_generation_id' => $envelope->providerGenerationId,
                'payload' => $envelope->payload,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function unprocessedDuplicate(WebhookEnvelopeData $envelope): ?WebhookEvent
    {
        $existing = WebhookEvent::query()->where('provider_event_id', $envelope->eventId)->firstOrFail();
        Log::info('Duplicate Pixelforge webhook delivery.', ['event' => $envelope->eventId, 'processed' => $existing->processed_at !== null]);

        return $existing->processed_at === null ? $existing : null;
    }
}
