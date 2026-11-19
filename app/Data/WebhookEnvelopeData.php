<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The outside of a Pixelforge webhook event: who it is and what it's about.
 * The generation inside (`data.object`) is mapped later, by the processing job.
 */
final readonly class WebhookEnvelopeData
{
    /**
     * @param  array<array-key, mixed>  $payload  the full decoded event, stored for audit and replay
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $providerGenerationId,
        public array $payload,
    ) {}
}
