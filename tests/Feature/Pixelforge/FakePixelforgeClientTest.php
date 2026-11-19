<?php

declare(strict_types=1);

use App\Contracts\PixelforgeClient;
use App\Data\GenerationRequestData;
use App\Data\ProviderGenerationData;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Exceptions\Pixelforge\RateLimitedException;
use Tests\Fakes\FakePixelforgeClient;

// The fake underpins later money tests, so its own behaviour is pinned down here.

it('replaces the bound client', function () {
    $fake = fakePixelforge();

    expect(app(PixelforgeClient::class))->toBe($fake);
});

it('is idempotent per client reference, like the real API', function () {
    $fake = fakePixelforge();
    $request = new GenerationRequestData('p', '1024x1024', 'low');

    $first = $fake->createGeneration($request, 'ref-1');
    $retry = $fake->createGeneration($request, 'ref-1');

    expect($retry)->toBe($first)
        ->and($fake->submissions)->toHaveCount(2)
        ->and($fake->billableGenerationCount())->toBe(1);
});

it('fails scripted create calls in order, then succeeds', function () {
    $fake = fakePixelforge()->failNextCreateWith(new RateLimitedException('slow down', 5));
    $request = new GenerationRequestData('p', '1024x1024', 'low');

    expect(fn () => $fake->createGeneration($request, 'ref'))->toThrow(RateLimitedException::class)
        ->and($fake->createGeneration($request, 'ref')->status)->toBe(GenerationStatus::Queued);
});

it('serves the documented pricing unless told otherwise', function () {
    expect(fakePixelforge()->getPricing()->rates)->toBe(FakePixelforgeClient::RATES);
});

it('stamps created generations with the rates they will be billed at', function () {
    $generation = fakePixelforge()->createGeneration(new GenerationRequestData('p', '1024x1024', 'low'), 'ref');

    expect($generation->rates)->toBe(FakePixelforgeClient::RATES);
});

it('returns, or throws, what was set for a generation', function () {
    $fake = fakePixelforge()->setGeneration(new ProviderGenerationData('gen_1', GenerationStatus::Processing));

    expect($fake->getGeneration('gen_1')->status)->toBe(GenerationStatus::Processing)
        ->and(fn () => $fake->getGeneration('gen_unknown'))->toThrow(GenerationNotFoundException::class);
});
