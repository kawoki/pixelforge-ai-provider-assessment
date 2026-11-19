<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\RateCardData;
use App\Enums\GenerationStatus;
use App\Support\Money\Usd;
use App\Support\Money\UsdCast;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One image generation and everything it cost (ADR-0004, ADR-0006, ADR-0008).
 *
 * @property string $id
 * @property int $user_id
 * @property string $client_reference
 * @property string|null $provider_generation_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_image_urls
 * @property GenerationStatus $status
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_expires_at
 * @property array{markup: string, rates: array<string, string>} $rate_card
 * @property Usd $estimated_user_cost_min
 * @property Usd $estimated_user_cost_max
 * @property array<string, int>|null $usage
 * @property Usd|null $actual_provider_cost
 * @property Usd|null $actual_user_cost
 * @property int $submission_attempts
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_provider_event_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'user_id', 'client_reference', 'provider_generation_id',
    'prompt', 'size', 'quality', 'reference_image_urls',
    'status', 'failure_code', 'failure_message', 'image_url', 'image_expires_at',
    'rate_card', 'estimated_user_cost_min', 'estimated_user_cost_max',
    'usage', 'actual_provider_cost', 'actual_user_cost',
    'submission_attempts', 'submitted_at', 'started_at', 'completed_at', 'last_provider_event_at',
])]
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * The rate card this generation is priced with, never the current config.
     */
    public function rateCardSnapshot(): RateCardData
    {
        return RateCardData::fromArray($this->rate_card);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_image_urls' => 'array',
            'rate_card' => 'array',
            'usage' => 'array',
            'estimated_user_cost_min' => UsdCast::class,
            'estimated_user_cost_max' => UsdCast::class,
            'actual_provider_cost' => UsdCast::class,
            'actual_user_cost' => UsdCast::class,
            ...self::timestampCasts(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function timestampCasts(): array
    {
        return array_fill_keys(
            ['image_expires_at', 'submitted_at', 'started_at', 'completed_at', 'last_provider_event_at'],
            'datetime',
        );
    }
}
