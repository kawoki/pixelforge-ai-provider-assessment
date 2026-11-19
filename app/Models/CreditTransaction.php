<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditTransactionType;
use App\Support\Money\Usd;
use App\Support\Money\UsdCast;
use Carbon\CarbonImmutable;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only ledger entry (ADR-0008, ADR-0011). Rows are never updated or deleted;
 * corrections are new `adjustment` entries.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property CreditTransactionType $type
 * @property Usd $amount
 * @property Usd $balance_after
 * @property string $idempotency_key
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount', 'balance_after', 'idempotency_key', 'meta'])]
final class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Credit transactions are append-only.'));
        self::deleting(fn () => throw new LogicException('Credit transactions are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => UsdCast::class,
            'balance_after' => UsdCast::class,
            'meta' => 'array',
        ];
    }
}
