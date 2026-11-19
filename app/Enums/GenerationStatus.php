<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Generation lifecycle (ADR-0006). Transitions only move forward by rank;
 * terminal states are immutable. Provider status names pending Q8.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isTerminal() && $next->rank() > $this->rank();
    }

    /**
     * What the user sees. "Pending" (not yet sent to Pixelforge) is shown as queued.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isTerminal()));
    }
}
