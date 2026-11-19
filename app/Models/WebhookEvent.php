<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified Pixelforge webhook delivery, stored before processing (ADR-0007).
 *
 * @property int $id
 * @property string $provider_event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $processing_error
 * @property int $attempts
 */
#[Fillable([
    'provider_event_id', 'type', 'provider_generation_id', 'payload',
    'processed_at', 'processing_error', 'attempts',
])]
final class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory, MassPrunable;

    public const CREATED_AT = 'received_at';

    public const UPDATED_AT = null;

    /**
     * Processed events are kept for audit and replay for a while, then pruned daily (F-13).
     * Unprocessed ones are never pruned: they may still need attention.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', now()->subDays((int) config('monitoring.retention.webhook_events_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
