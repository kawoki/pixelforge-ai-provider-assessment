<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TokenType;
use App\Support\Money\Usd;
use Brick\Math\BigDecimal;

/**
 * One line of a price breakdown: tokens of one type × that type's rate (provider cost).
 */
final readonly class CostLineData
{
    public function __construct(
        public TokenType $type,
        public int $tokens,
        public BigDecimal $ratePerMillion,
        public Usd $providerCost,
    ) {}
}
