<?php

declare(strict_types=1);

use App\Data\GenerationRequestData;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Services\Pixelforge\PricingPayloadMapper;
use App\Services\Pricing\PricingCatalogRepository;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Facades\Log;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    config(['cache.default' => 'array']);
});

it('reads Pixelforge\'s live pricing', function () {
    $fake = fakePixelforge();

    expect(app(PricingCatalogRepository::class)->current())->toEqual($fake->getPricing());
});

it('stops asking for a minute once Pixelforge\'s pricing is unreachable', function () {
    $fake = fakePixelforge()->setPricing(new ProviderUnavailableException('down'));
    Log::spy();

    app(PricingCatalogRepository::class)->current();
    app(PricingCatalogRepository::class)->current();

    Log::shouldHaveReceived('warning')->once();
});

it('falls back to the config copy when Pixelforge is unreachable', function () {
    fakePixelforge()->setPricing(new ProviderUnavailableException('down'));
    Log::spy();

    $catalog = app(PricingCatalogRepository::class)->current();

    expect($catalog->tokenRules->tokensPerReferenceImage)->toBe(765);
    Log::shouldHaveReceived('warning')->once();
});

it('ships a config fallback identical to the documented pricing', function () {
    $mapper = new PricingPayloadMapper;

    expect($mapper->map(config('pixelforge.pricing.fallback')))->toEqual($mapper->map(PixelforgeFixtures::pricingPayload()));
});

it('can estimate every size and quality the app offers', function () {
    fakePixelforge();
    $catalog = app(PricingCatalogRepository::class)->current();

    foreach (config('pixelforge.options.sizes') as $size) {
        foreach (config('pixelforge.options.qualities') as $quality) {
            $estimate = app(PricingService::class)->estimate(new GenerationRequestData('a prompt', $size, $quality), $catalog);

            expect($estimate->max->userCost->isLessThan($estimate->min->userCost))->toBeFalse();
        }
    }
});
