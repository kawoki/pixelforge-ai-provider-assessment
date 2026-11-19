<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use Illuminate\Database\QueryException;

it('allows only one charge per generation, whatever the idempotency key', function () {
    $generation = Generation::factory()->succeeded()->create();
    CreditTransaction::factory()->chargeFor($generation)->create();

    CreditTransaction::factory()->chargeFor($generation)->create(['idempotency_key' => 'charge:other-key']);
})->throws(QueryException::class, 'credit_transactions_one_charge_per_generation');

it('rejects a reused idempotency key', function () {
    CreditTransaction::factory()->create(['idempotency_key' => 'purchase:abc']);

    CreditTransaction::factory()->create(['idempotency_key' => 'purchase:abc']);
})->throws(QueryException::class, 'credit_transactions_idempotency_key_unique');

it('requires charges to be negative and tied to a generation', function (array $attributes) {
    $generation = Generation::factory()->succeeded()->create();

    CreditTransaction::factory()->chargeFor($generation)->create($attributes);
})->throws(QueryException::class, 'credit_transactions_charge_shape')->with([
    'positive amount' => [['amount' => '0.05']],
    'no generation' => [['generation_id' => null]],
]);

it('requires purchases to be positive', function () {
    CreditTransaction::factory()->create(['type' => CreditTransactionType::Purchase, 'amount' => '-5']);
})->throws(QueryException::class, 'credit_transactions_purchase_positive');

it('is append-only', function (Closure $mutate) {
    $mutate(CreditTransaction::factory()->create());
})->throws(LogicException::class, 'append-only')->with([
    'update' => [fn (CreditTransaction $t) => $t->update(['amount' => '999'])],
    'delete' => [fn (CreditTransaction $t) => $t->delete()],
]);
