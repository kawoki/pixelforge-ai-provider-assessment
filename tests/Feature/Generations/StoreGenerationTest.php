<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->fake = fakePixelforge();
    $this->user = User::factory()->withCredits()->create();
    $this->actingAs($this->user);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationInput(array $overrides = []): array
{
    return ['prompt' => 'A lighthouse at dawn [succeed]', 'size' => '1024x1024', 'quality' => 'medium', 'reference_image_urls' => [], ...$overrides];
}

it('records a pending generation and queues its submission', function () {
    Queue::fake();

    $this->post(route('generations.store'), generationInput())->assertRedirect(route('generations.index'));
    $this->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page->hasFlash('toast.type', 'success'));

    $generation = $this->user->generations()->sole();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and(Str::isUuid($generation->client_reference))->toBeTrue()
        ->and($generation->prompt)->toBe('A lighthouse at dawn [succeed]');
    Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job) => $job->generation->is($generation));
});

it('computes the estimate on the server and ignores anything price-related from the client', function () {
    Queue::fake();

    $this->post(route('generations.store'), generationInput([
        'estimated_user_cost_min' => '0', 'estimated_user_cost_max' => '0', 'rate_card' => ['markup' => '0'],
    ]));

    $generation = $this->user->generations()->sole();

    // 30 chars → 8 text tokens (6..10); medium 1024x1024 = 1,100 output; reasoning 200..2,000.
    // min: (6×5 + 1100×40 + 200×12.5) / 1e6 × 1.25 = 0.0581625; max: (10×5 + 44000 + 25000) / 1e6 × 1.25 = 0.0863125
    expect($generation->estimated_user_cost_min->toDecimalString())->toBe('0.058162500000')
        ->and($generation->estimated_user_cost_max->toDecimalString())->toBe('0.086312500000')
        ->and($generation->rate_card['markup'])->toBe('0.25');
});

it('submits to Pixelforge in the background and ends up queued there', function () {
    $this->post(route('generations.store'), generationInput());

    $generation = $this->user->generations()->sole();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_generation_id)->not->toBeNull()
        ->and($this->fake->billableGenerationCount())->toBe(1)
        ->and($this->fake->submissions[0]['clientReference'])->toBe($generation->client_reference);
});

it('rejects invalid input without creating anything', function () {
    Queue::fake();

    $this->post(route('generations.store'), generationInput(['prompt' => '', 'size' => 'huge']))
        ->assertSessionHasErrors(['prompt', 'size']);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('limits how fast one user can submit', function () {
    Queue::fake();

    foreach (range(1, 10) as $_) {
        $this->post(route('generations.store'), generationInput())->assertRedirect();
    }

    $this->post(route('generations.store'), generationInput())->assertTooManyRequests();
});
