<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Data\CreditPackData;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money\Usd;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only writer of credit_transactions and users.balance (ADR-0008, ADR-0011).
 * Each entry and the cached balance change together, under a lock on the user row.
 */
final readonly class CreditLedgerService
{
    /**
     * Record what a succeeded generation cost its owner. The unique key `charge:{id}`
     * (and a partial unique index) make a second charge for the same generation impossible.
     */
    public function charge(Generation $generation, Usd $userCost): CreditTransaction
    {
        return $this->record(
            userId: $generation->user_id,
            type: CreditTransactionType::Charge,
            amount: $userCost->negated(),
            idempotencyKey: 'charge:'.$generation->id,
            generationId: $generation->id,
        );
    }

    /**
     * Add a pack's credits. Idempotent per key: a double-click or a resubmitted form returns
     * the original purchase instead of crediting twice.
     */
    public function purchase(User $user, CreditPackData $pack, string $idempotencyKey): CreditTransaction
    {
        $key = "purchase:{$idempotencyKey}";

        try {
            return $this->record($user->id, CreditTransactionType::Purchase, $pack->amount, $key, meta: ['pack' => $pack->id]);
        } catch (UniqueConstraintViolationException) {
            return $this->existingPurchase($user, $key);
        }
    }

    /**
     * A correction by an operator, with the reason kept on the entry.
     */
    public function adjust(User $user, Usd $amount, string $reason, ?string $generationId = null): CreditTransaction
    {
        return $this->record(
            userId: $user->id,
            type: CreditTransactionType::Adjustment,
            amount: $amount,
            idempotencyKey: 'adjustment:'.Str::uuid(),
            generationId: $generationId,
            meta: ['reason' => $reason],
        );
    }

    private function existingPurchase(User $user, string $key): CreditTransaction
    {
        $existing = CreditTransaction::query()->where('idempotency_key', $key)->firstOrFail();

        return $existing->user_id === $user->id ? $existing : throw new LogicException('Purchase key belongs to another user.');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function record(int $userId, CreditTransactionType $type, Usd $amount, string $idempotencyKey, ?string $generationId = null, ?array $meta = null): CreditTransaction
    {
        return DB::transaction(function () use ($userId, $type, $amount, $idempotencyKey, $generationId, $meta): CreditTransaction {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $balance = $user->balance->plus($amount);
            $user->forceFill(['balance' => $balance])->save();

            return CreditTransaction::query()->create([
                'user_id' => $userId, 'generation_id' => $generationId, 'type' => $type, 'amount' => $amount,
                'balance_after' => $balance, 'idempotency_key' => $idempotencyKey, 'meta' => $meta,
            ]);
        });
    }
}
