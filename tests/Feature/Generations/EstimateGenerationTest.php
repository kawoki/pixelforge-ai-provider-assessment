<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Models\User;

beforeEach(function () {
    $this->fake = fakePixelforge();
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function estimateInput(array $overrides = []): array
{
    return [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_image_urls' => ['https://a.test/1.png', 'https://a.test/2.png'],
        ...$overrides,
    ];
}

it('returns the price range users will pay (docs max example × 1.25)', function () {
    $this->postJson(route('generations.estimate'), estimateInput())
        ->assertOk()
        ->assertJsonPath('min.amount', '0.357781250000')
        ->assertJsonPath('max.amount', '0.467343750000')
        ->assertJsonPath('max.formatted', '$0.4673');
});

it('explains the range per token type', function () {
    $response = $this->postJson(route('generations.estimate'), estimateInput())->assertOk();

    expect($response->json('lines'))->toHaveCount(4)
        ->and($response->json('lines.1'))->toMatchArray([
            'type' => 'input_image',
            'label' => 'Reference images',
            'tokens' => ['min' => 1530, 'max' => 1530],
        ])
        ->and($response->json('lines.3.tokens'))->toBe(['min' => 1000, 'max' => 8000]);
});

it('shows user prices only, never Pixelforge\'s cost or our margin', function () {
    $json = json_encode($this->postJson(route('generations.estimate'), estimateInput())->json());

    expect($json)->not->toContain('provider')->not->toContain('markup')->not->toContain('0.373875');
});

it('estimates while the prompt is still empty', function () {
    $this->postJson(route('generations.estimate'), estimateInput(['prompt' => '']))->assertOk();
});

it('still estimates when Pixelforge\'s pricing endpoint is down', function () {
    $this->fake->setPricing(new ProviderUnavailableException('down'));

    $this->postJson(route('generations.estimate'), estimateInput())->assertOk()->assertJsonPath('max.amount', '0.467343750000');
});

it('validates input like Pixelforge does', function (array $overrides, string $field) {
    $this->postJson(route('generations.estimate'), estimateInput($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['reference_image_urls' => array_map(fn ($i) => "https://a.test/{$i}.png", range(1, 5))], 'reference_image_urls'],
    'not a URL' => [['reference_image_urls' => ['not a url']], 'reference_image_urls.0'],
    'not http(s)' => [['reference_image_urls' => ['ftp://a.test/1.png']], 'reference_image_urls.0'],
    'duplicate URL' => [['reference_image_urls' => ['https://a.test/1.png', 'https://a.test/1.png']], 'reference_image_urls.0'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
]);

it('requires a signed-in user', function () {
    auth()->logout();

    $this->postJson(route('generations.estimate'), estimateInput())->assertUnauthorized();
});
