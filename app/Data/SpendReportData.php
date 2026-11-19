<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Usd;
use Carbon\CarbonImmutable;

/**
 * Credit spend, provider cost and profit per day and per user, reconciled with
 * Pixelforge's own billing (ADR-0012).
 */
final readonly class SpendReportData
{
    /**
     * @param  list<SpendDayData>  $days  newest first, every day in the range
     * @param  list<SpendUserRowData>  $users  totals per user for the whole range, biggest spender first
     */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $days,
        public array $users,
        public SpendFiguresData $total,
        public bool $pixelforgeAvailable,
    ) {}

    public function pixelforgeTotal(): ?Usd
    {
        if (! $this->pixelforgeAvailable) {
            return null;
        }

        return array_reduce($this->days, fn (Usd $sum, SpendDayData $day): Usd => $sum->plus($day->pixelforge->cost ?? Usd::zero()), Usd::zero());
    }

    public function mismatchedDays(): int
    {
        return count(array_filter($this->days, fn (SpendDayData $day): bool => $day->matchesPixelforge() === false));
    }
}
