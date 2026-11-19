<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

it('creates a demo user with credits and an operator, ready to sign in', function () {
    $this->seed(DemoSeeder::class);

    $demo = User::query()->where('email', 'demo@example.com')->sole();
    $admin = User::query()->where('email', 'admin@example.com')->sole();

    expect($demo->is_admin)->toBeFalse()
        ->and($demo->balance->toDecimalString())->toBe('10.000000000000')
        ->and($demo->hasVerifiedEmail())->toBeTrue()
        ->and($admin->is_admin)->toBeTrue()
        ->and(Hash::check('password', $demo->password))->toBeTrue(); // local/testing default
});

it('can run again without granting credits twice', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::query()->where('email', 'demo@example.com')->sole()->balance->toDecimalString())->toBe('10.000000000000');
});

it('uses the configured password when one is set', function () {
    config(['demo.user_password' => 'a-long-demo-password']);

    $this->seed(DemoSeeder::class);

    expect(Hash::check('a-long-demo-password', User::query()->where('email', 'demo@example.com')->sole()->password))->toBeTrue();
});
