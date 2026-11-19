<?php

declare(strict_types=1);

use App\Actions\Generations\ApplyProviderUpdateAction;
use App\Actions\Generations\SubmitGenerationToProviderAction;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    $this->fake = fakePixelforge();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function catchUp(): void
{
    test()->post(route('generations.catch-up'))->assertNoContent();
}

/**
 * One generation stuck before submission (the dead queue never ran its job), and one that
 * Pixelforge finished but whose webhook nobody processed.
 *
 * @return array{0: Generation, 1: Generation}
 */
function strandedGenerations(User $user): array
{
    $unsubmitted = Generation::factory()->for($user)->create();
    $finished = Generation::factory()->for($user)->queued()->create(['estimated_user_cost_max' => '1']);
    test()->fake->setGeneration((new GenerationPayloadMapper)->map(PixelforgeFixtures::generationPayload([
        'id' => $finished->provider_generation_id, 'metadata' => ['client_reference' => $finished->client_reference],
    ])));

    return [$unsubmitted, $finished];
}

it('does nothing while background processing is healthy', function () {
    backgroundIsUp();
    strandedGenerations($this->user);

    catchUp();

    expect($this->fake->submissions)->toBeEmpty()->and($this->fake->retrievals)->toBeEmpty();
});

it('moves the user\'s generations forward itself while the queue is down', function () {
    backgroundIsDown();
    [$unsubmitted, $finished] = strandedGenerations($this->user);

    catchUp();

    expect($unsubmitted->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($finished->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(CreditTransaction::count())->toBe(1);
});

it('never double-submits or double-charges when the queue comes back and runs the old jobs', function () {
    backgroundIsDown();
    [$unsubmitted, $finished] = strandedGenerations($this->user);
    catchUp();

    app(SubmitGenerationToProviderAction::class)->execute($unsubmitted->fresh());
    app(ApplyProviderUpdateAction::class)->execute($this->fake->getGeneration((string) $finished->provider_generation_id));

    expect($this->fake->billableGenerationCount())->toBe(1)->and(CreditTransaction::count())->toBe(1);
});

it('checks each generation at most once per window, however many tabs poll', function () {
    backgroundIsDown();
    Generation::factory()->for($this->user)->processing()->create();
    $this->fake->setGeneration(new GenerationNotFoundException('later'), Generation::sole()->provider_generation_id);

    catchUp();
    catchUp();

    expect($this->fake->retrievals)->toHaveCount(1);
});

it('only touches the signed-in user\'s generations', function () {
    backgroundIsDown();
    strandedGenerations(User::factory()->create());

    catchUp();

    expect($this->fake->submissions)->toBeEmpty()->and($this->fake->retrievals)->toBeEmpty();
});

it('stays within a global budget of Pixelforge requests', function () {
    backgroundIsDown();
    config(['pixelforge.fallback.max_requests_per_minute' => 1]);
    strandedGenerations($this->user);

    catchUp();

    expect(count($this->fake->submissions) + count($this->fake->retrievals))->toBe(1);
});

it('requires a signed-in user', function () {
    auth()->logout();

    $this->postJson(route('generations.catch-up'))->assertUnauthorized();
});
