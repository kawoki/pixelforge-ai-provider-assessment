<?php

declare(strict_types=1);

use App\Data\ProviderGenerationData;
use App\Data\RateCardData;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationLifecycleService;
use Database\Factories\GenerationFactory;

function lifecycle(): GenerationLifecycleService
{
    return app(GenerationLifecycleService::class);
}

it('applies forward transitions and refreshes the caller\'s model', function () {
    $generation = Generation::factory()->queued()->create();

    expect(lifecycle()->transition($generation, GenerationStatus::Processing, ['started_at' => now()]))->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at)->not->toBeNull();
});

it('ignores backward and duplicate transitions', function (GenerationStatus $to) {
    $generation = Generation::factory()->processing()->create();

    expect(lifecycle()->transition($generation, $to))->toBeFalse()
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
})->with([GenerationStatus::Queued, GenerationStatus::Processing]);

it('never changes a finished generation', function () {
    $generation = Generation::factory()->failed()->create();

    expect(lifecycle()->fail($generation, 'other', 'Other reason'))->toBeFalse()
        ->and($generation->fresh()->failure_code)->toBe('generation_failed');
});

it('records acceptance: provider id, billing rates and queued status', function () {
    $generation = Generation::factory()->create();
    $rateCard = RateCardData::fromArray(GenerationFactory::testRateCard());

    lifecycle()->recordAcceptance($generation, new ProviderGenerationData('gen_abc', GenerationStatus::Queued), $rateCard);

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_generation_id)->toBe('gen_abc')
        ->and($generation->submitted_at)->not->toBeNull()
        ->and($generation->rate_card)->toEqual($rateCard->toArray());
});

it('records acceptance without moving back a generation a webhook already advanced', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Processing]);
    $rateCard = RateCardData::fromArray(GenerationFactory::testRateCard());

    lifecycle()->recordAcceptance($generation, new ProviderGenerationData('gen_abc', GenerationStatus::Queued), $rateCard);

    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->provider_generation_id)->toBe('gen_abc');
});

it('abandons only submissions Pixelforge never accepted', function () {
    $neverAccepted = Generation::factory()->create();
    $accepted = Generation::factory()->queued()->create();

    expect(lifecycle()->abandonSubmission($neverAccepted, 'provider_busy', 'Busy'))->toBeTrue()
        ->and(lifecycle()->abandonSubmission($accepted, 'provider_busy', 'Busy'))->toBeFalse()
        ->and($neverAccepted->status)->toBe(GenerationStatus::Failed)
        ->and($accepted->fresh()->status)->toBe(GenerationStatus::Queued);
});
