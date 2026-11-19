<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\ProviderGenerationData;
use App\Data\TokenUsageData;
use App\Enums\GenerationStatus;
use App\Enums\TokenType;

/**
 * Translates a Pixelforge generation object (PIXELFORGE_API.md → "The generation object")
 * into ProviderGenerationData. Used for create/get responses and webhook `data.object`.
 */
final readonly class GenerationPayloadMapper
{
    private const array STATUSES = [
        'queued' => GenerationStatus::Queued,
        'processing' => GenerationStatus::Processing,
        'succeeded' => GenerationStatus::Succeeded,
        'failed' => GenerationStatus::Failed,
    ];

    public function map(mixed $payload): ProviderGenerationData
    {
        $object = PayloadReader::of($payload, 'generation');

        return new ProviderGenerationData(
            providerGenerationId: $object->string('id'),
            status: self::STATUSES[$object->string('status')] ?? $object->fail('status', 'an unknown status'),
            clientReference: $object->optionalString('metadata.client_reference'),
            usage: $object->has('usage') ? $this->usage($object->child('usage')) : null,
            rates: $object->has('rates') ? $this->rates($object->child('rates')) : null,
            imageUrl: $object->optionalString('image_url'),
            imageExpiresAt: $object->optionalTimestamp('image_url_expires_at'),
            failureCode: $object->optionalString('error.code'),
            failureMessage: $object->optionalString('error.message'),
            startedAt: $object->optionalTimestamp('started_at'),
            completedAt: $object->optionalTimestamp('completed_at'),
        );
    }

    /**
     * Refuses usage whose total_tokens does not add up: that would mean a token type we
     * don't know how to price, and silently ignoring it would under-record the cost.
     */
    private function usage(PayloadReader $usage): TokenUsageData
    {
        $counts = [];

        foreach (TokenType::cases() as $type) {
            $counts[$type->value] = $usage->int($type->providerKey());
        }

        $total = $usage->optionalInt('total_tokens');

        return $total === null || $total === array_sum($counts)
            ? TokenUsageData::fromArray($counts)
            : $usage->fail('total_tokens', 'not the sum of the known token types');
    }

    /**
     * @return array<string, string> USD per 1M tokens, keyed by TokenType value
     */
    private function rates(PayloadReader $rates): array
    {
        $parsed = [];

        foreach (TokenType::cases() as $type) {
            $parsed[$type->value] = $rates->string($type->providerKey());
        }

        return $parsed;
    }
}
