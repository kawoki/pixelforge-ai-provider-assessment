<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\WebhookEnvelopeData;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use JsonException;

/**
 * Reads a webhook event envelope (PIXELFORGE_API.md → Webhooks):
 * {"id": "evt_…", "object": "event", "type": "generation.succeeded", "data": {"object": {…generation…}}}
 */
final readonly class WebhookEnvelopeMapper
{
    public function fromRawBody(string $rawBody): WebhookEnvelopeData
    {
        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedProviderResponseException('Unexpected Pixelforge webhook: body is not JSON.');
        }

        $event = PayloadReader::of($payload, 'webhook event');

        /** @var array<array-key, mixed> $payload */
        return new WebhookEnvelopeData($event->string('id'), $event->string('type'), $event->optionalString('data.object.id'), $payload);
    }
}
