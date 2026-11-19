<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use App\Services\Pixelforge\PricingPayloadMapper;
use Tests\Support\PixelforgeFixtures;

it('maps the docs\' pricing response', function () {
    $catalog = (new PricingPayloadMapper)->map(PixelforgeFixtures::pricingPayload());

    expect($catalog->rates)->toBe(['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50'])
        ->and($catalog->tokenRules->charactersPerTextToken)->toBe(4)
        ->and((string) $catalog->tokenRules->textTokenVariance)->toBe('0.15')
        ->and($catalog->tokenRules->tokensPerReferenceImage)->toBe(765)
        ->and($catalog->tokenRules->outputTokens('high', '1536x1024'))->toBe(6450)
        ->and($catalog->tokenRules->reasoningTokens('medium'))->toBe(['min' => 200, 'max' => 2000]);
});

it('round-trips through its published shape (used by the cache and config fallback)', function () {
    $mapper = new PricingPayloadMapper;
    $catalog = $mapper->map(PixelforgeFixtures::pricingPayload());

    expect($mapper->map($catalog->toArray()))->toEqual($catalog);
});

it('rejects malformed pricing', function (Closure $break) {
    $payload = PixelforgeFixtures::pricingPayload();
    $break($payload);

    (new PricingPayloadMapper)->map($payload);
})->throws(UnexpectedProviderResponseException::class)->with([
    'float rate' => [function (array &$p) {
        $p['rates']['output_tokens'] = 40.0;
    }],
    'missing rate' => [function (array &$p) {
        unset($p['rates']['reasoning_tokens']);
    }],
    'zero chars per token' => [function (array &$p) {
        $p['tokens']['characters_per_text_token'] = 0;
    }],
    'non-integer output' => [function (array &$p) {
        $p['tokens']['output']['low']['1024x1024'] = '280';
    }],
    'missing reasoning max' => [function (array &$p) {
        unset($p['tokens']['reasoning']['high']['max']);
    }],
]);
