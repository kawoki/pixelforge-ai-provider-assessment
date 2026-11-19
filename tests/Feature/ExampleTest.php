<?php

declare(strict_types=1);

use App\Models\User;

it('sends guests straight to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends signed-in users to the Generate page', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('generations.create'));
});

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});
