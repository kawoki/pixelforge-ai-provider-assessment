<?php

declare(strict_types=1);

use App\Data\ProviderGenerationData;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Tests\Support\PixelforgeFixtures;

function mapGeneration(mixed $payload): ProviderGenerationData
{
    return (new GenerationPayloadMapper)->map($payload);
}

it('maps the docs\' succeeded generation', function () {
    $generation = mapGeneration(PixelforgeFixtures::generationPayload());

    expect($generation->providerGenerationId)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->clientReference)->toBe('ref-1')
        ->and($generation->usage?->toArray())->toBe(['input_text' => 12, 'input_image' => 765, 'output' => 6450, 'reasoning' => 3184])
        ->and($generation->rates)->toBe(['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50'])
        ->and($generation->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->imageExpiresAt?->toIso8601String())->toBe('2026-11-05T14:03:29+00:00')
        ->and($generation->startedAt?->toIso8601String())->toBe('2026-11-04T14:03:13+00:00')
        ->and($generation->completedAt?->toIso8601String())->toBe('2026-11-04T14:03:29+00:00');
});

it('maps a queued generation (usage and image not yet known)', function () {
    $generation = mapGeneration(PixelforgeFixtures::generationPayload([
        'status' => 'queued', 'usage' => null, 'image_url' => null, 'image_url_expires_at' => null, 'completed_at' => null,
    ]));

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->usage)->toBeNull()
        ->and($generation->imageUrl)->toBeNull()
        ->and($generation->rates)->not->toBeNull();
});

it('maps a failed generation with its error', function () {
    $generation = mapGeneration(PixelforgeFixtures::generationPayload([
        'status' => 'failed', 'usage' => null, 'image_url' => null,
        'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side while generating.'],
    ]));

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->failureCode)->toBe('internal_error')
        ->and($generation->failureMessage)->toStartWith('Something went wrong')
        ->and($generation->usage)->toBeNull();
});

it('maps every documented status', function (string $provider, GenerationStatus $ours) {
    expect(mapGeneration(PixelforgeFixtures::generationPayload(['status' => $provider]))->status)->toBe($ours);
})->with([
    ['queued', GenerationStatus::Queued],
    ['processing', GenerationStatus::Processing],
    ['succeeded', GenerationStatus::Succeeded],
    ['failed', GenerationStatus::Failed],
]);

it('refuses usage that does not add up, so no token type is ever left unbilled', function () {
    $payload = PixelforgeFixtures::generationPayload();
    $payload['usage']['total_tokens'] = 99_999;

    mapGeneration($payload);
})->throws(UnexpectedProviderResponseException::class, 'total_tokens');

it('rejects malformed generations', function (array $overrides) {
    mapGeneration(PixelforgeFixtures::generationPayload($overrides));
})->throws(UnexpectedProviderResponseException::class)->with([
    'missing id' => [['id' => null]],
    'unknown status' => [['status' => 'cancelled']],
    'non-string id' => [['id' => 42]],
    'fractional tokens' => [['usage' => ['input_text_tokens' => 1.5, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]]],
    'missing token type' => [['usage' => ['input_text_tokens' => 1]]],
    'float rate' => [['rates' => ['input_text_tokens' => 5.0, 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']]],
    'bad timestamp' => [['completed_at' => 'soon']],
]);

it('rejects a payload that is not an object', function () {
    mapGeneration('ok');
})->throws(UnexpectedProviderResponseException::class, 'not an object');
