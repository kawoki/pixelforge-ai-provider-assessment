<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Usd;

/**
 * A user's credits: the ledger balance, what running generations may still cost (their
 * estimate maximum, held back), and what is left to spend (ADR-0011).
 */
final readonly class BalanceSummaryData
{
    public function __construct(
        public Usd $balance,
        public Usd $reserved,
    ) {}

    public function available(): Usd
    {
        return $this->balance->minus($this->reserved);
    }

    public function canAfford(Usd $maximumCost): bool
    {
        return ! $maximumCost->isGreaterThan($this->available());
    }
}
