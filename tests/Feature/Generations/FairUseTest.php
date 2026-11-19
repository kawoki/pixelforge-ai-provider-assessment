<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
    fakePixelforge();
    $this->user = User::factory()->withCredits('25.00')->create();
    $this->actingAs($this->user);
});

function startGeneration(): TestResponse
{
    return test()->post(route('generations.store'), ['prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'medium']);
}

it('lets one user run at most 3 at a time, so others still get Pixelforge\'s shared slots', function () {
    foreach (range(1, 3) as $_) {
        startGeneration()->assertRedirect(route('generations.index'));
    }

    startGeneration()->assertSessionHasErrors(['limit' => 'You already have 3 generations in progress. Wait for one to finish: up to 3 can run at a time.']);
    expect(Generation::count())->toBe(3);
});

it('frees a slot as soon as one finishes', function () {
    Generation::factory()->count(2)->for($this->user)->processing()->create();
    Generation::factory()->for($this->user)->succeeded()->create();

    startGeneration()->assertRedirect(route('generations.index'));
});

it('caps a user\'s generations per UTC day, so nobody uses up the account\'s 500', function () {
    config(['pixelforge.per_user.max_per_day' => 2]);
    Generation::factory()->count(2)->for($this->user)->succeeded()->create();

    startGeneration()->assertSessionHasErrors(['limit' => "You've reached today's limit of 2 generations. It resets at midnight UTC."]);
});

it('does not count submissions Pixelforge rejected towards the daily cap', function () {
    config(['pixelforge.per_user.max_per_day' => 1]);
    Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Failed, 'failure_code' => 'content_policy_violation', 'completed_at' => now()]);

    startGeneration()->assertRedirect(route('generations.index'));
});

it('tells the Generate page before the user even submits', function () {
    Generation::factory()->count(3)->for($this->user)->queued()->create();

    $this->postJson(route('generations.estimate'), ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'medium'])
        ->assertJsonPath('limit_reason', 'You already have 3 generations in progress. Wait for one to finish: up to 3 can run at a time.');
});

it('limits each user separately', function () {
    Generation::factory()->count(3)->for(User::factory()->create())->queued()->create();

    startGeneration()->assertRedirect(route('generations.index'));
});
