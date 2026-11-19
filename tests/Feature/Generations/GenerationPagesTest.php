<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['generations.create', 'generations.index']);

it('offers exactly the options Pixelforge accepts', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('options.qualities', ['low', 'medium', 'high'])
            ->where('options.max_reference_images', 4)
            ->where('options.prompt_max_length', 4000));
});

it('lists only the signed-in user\'s generations, newest first', function () {
    $user = User::factory()->create();
    $older = Generation::factory()->for($user)->create(['created_at' => now()->subHour()]);
    $newer = Generation::factory()->for($user)->succeeded()->create();
    Generation::factory()->create(); // someone else's

    $this->actingAs($user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.1.id', $older->id)
            ->where('generations.data.0.actual_cost.formatted', '$0.0707')
            ->where('generations.data.1.estimated_cost.max.formatted', '$0.0863'));
});

it('never exposes Pixelforge\'s cost, rates or our margin to users', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();

    $props = json_encode($this->actingAs($user)->get(route('generations.index'))->viewData('page')['props']['generations']);

    expect($props)->not->toContain('provider')->not->toContain('rate_card')->not->toContain('0.056550');
});

describe('images', function () {
    it('shows the image while Pixelforge\'s link works', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['image_url' => 'https://pixelforge.promptedit.dev/images/a.png', 'image_expires_at' => now()->addHours(2)]);

        $this->actingAs($user)->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image.url', 'https://pixelforge.promptedit.dev/images/a.png')
            ->where('generations.data.0.image.expired', false));
    });

    it('says the image expired instead of showing a broken one', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['image_expires_at' => now()->subMinute()]);

        $this->actingAs($user)->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image.url', null)
            ->where('generations.data.0.image.expired', true));
    });

    it('never passes on a non-https image URL (it ends up in an href)', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['image_url' => 'javascript:alert(1)']);

        $this->actingAs($user)->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page->where('generations.data.0.image', null));
    });
});
