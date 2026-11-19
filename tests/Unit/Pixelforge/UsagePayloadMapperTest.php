<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use App\Services\Pixelforge\UsagePayloadMapper;

/**
 * GET /v1/usage response, verbatim from PIXELFORGE_API.md.
 *
 * @return array<string, mixed>
 */
function docsUsagePayload(): array
{
    return json_decode(<<<'JSON'
        {"object": "usage", "start_date": "2026-11-01", "end_date": "2026-11-02",
         "data": [
           {"date": "2026-11-01", "generations": 3, "input_text_tokens": 41, "input_image_tokens": 0, "output_tokens": 3300, "reasoning_tokens": 2950, "cost_usd": "0.169080000"},
           {"date": "2026-11-02", "generations": 0, "input_text_tokens": 0, "input_image_tokens": 0, "output_tokens": 0, "reasoning_tokens": 0, "cost_usd": "0.000000000"}],
         "total": {"generations": 3, "cost_usd": "0.169080000"}}
        JSON, true, flags: JSON_THROW_ON_ERROR);
}

it('maps the docs\' usage response exactly', function () {
    $usage = (new UsagePayloadMapper)->map(docsUsagePayload());

    expect($usage->days)->toHaveCount(2)
        ->and($usage->forDate('2026-11-01')?->cost->toDecimalString())->toBe('0.169080000000')
        ->and($usage->forDate('2026-11-01')?->generations)->toBe(3)
        ->and($usage->forDate('2026-11-02')?->cost->isZero())->toBeTrue()
        ->and($usage->forDate('2026-11-03'))->toBeNull();
});

it('refuses costs that are not exact decimal strings', function (mixed $cost) {
    $payload = docsUsagePayload();
    $payload['data'][0]['cost_usd'] = $cost;

    (new UsagePayloadMapper)->map($payload);
})->throws(UnexpectedProviderResponseException::class)->with([
    'float' => [0.16908],
    'garbage' => ['lots'],
]);
