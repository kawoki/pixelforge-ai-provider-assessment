<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Support\Money\Usd;
use Illuminate\Database\QueryException;

it('stores and reads money without any loss of precision', function () {
    $generation = Generation::factory()->succeeded()->create([
        'actual_provider_cost' => Usd::of('0.000000000008'),
        'actual_user_cost' => Usd::of('0.000000000010'),
    ]);

    $fresh = $generation->fresh();

    expect($fresh->actual_provider_cost)->toBeInstanceOf(Usd::class)
        ->and($fresh->actual_provider_cost->toDecimalString())->toBe('0.000000000008')
        ->and($fresh->actual_user_cost->toDecimalString())->toBe('0.000000000010');
});

it('refuses to store money given as a float', function () {
    Generation::factory()->make(['estimated_user_cost_max' => 0.1]);
})->throws(InvalidArgumentException::class, 'must be Usd, a decimal string or int');

it('keeps actual costs null until a generation succeeds', function () {
    $generation = Generation::factory()->failed()->create();

    expect($generation->fresh()->actual_user_cost)->toBeNull();
});

it('makes it impossible to record a cost on a failed generation', function () {
    Generation::factory()->failed()->create(['actual_provider_cost' => '0.01', 'actual_user_cost' => '0.0125']);
})->throws(QueryException::class, 'generations_actual_cost_only_when_succeeded');

it('makes it impossible to succeed without recording a cost', function () {
    Generation::factory()->succeeded()->create(['actual_provider_cost' => null, 'actual_user_cost' => null]);
})->throws(QueryException::class, 'generations_actual_cost_only_when_succeeded');

it('rejects unknown statuses at the database level', function () {
    Generation::factory()->create()->newQuery()->toBase()->update(['status' => 'cancelled']);
})->throws(QueryException::class, 'generations_status_check');

it('exposes the snapshotted rate card', function () {
    $generation = Generation::factory()->create();

    expect((string) $generation->rateCardSnapshot()->markup)->toBe('0.25');
});

it('does not let users with generations be deleted', function () {
    $generation = Generation::factory()->create();

    $generation->user->delete();
})->throws(QueryException::class);

it('never mass-assigns admin rights or balance', function () {
    $user = User::query()->create([
        'name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'password',
        'is_admin' => true,
        'balance' => '1000000',
    ]);

    expect($user->fresh()->is_admin)->toBeFalse()
        ->and($user->fresh()->balance->isZero())->toBeTrue();
});
