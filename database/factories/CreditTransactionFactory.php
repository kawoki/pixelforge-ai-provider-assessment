<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Note: factories bypass CreditLedgerService, so they do not update users.balance.
 *
 * @extends Factory<CreditTransaction>
 */
final class CreditTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => CreditTransactionType::Purchase,
            'amount' => '10.000000000000',
            'balance_after' => '10.000000000000',
            'idempotency_key' => 'purchase:'.Str::uuid(),
        ];
    }

    public function chargeFor(Generation $generation): static
    {
        return $this->state(fn (): array => [
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => CreditTransactionType::Charge,
            'amount' => $generation->actual_user_cost?->negated() ?? '0',
            'balance_after' => '0',
            'idempotency_key' => 'charge:'.$generation->id,
        ]);
    }
}
