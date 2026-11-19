<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Usd;

/**
 * A fixed amount of credits users can buy. Amounts come from the server's list,
 * never from the request (ADR-0013).
 */
final readonly class CreditPackData
{
    public function __construct(
        public string $id,
        public string $label,
        public Usd $amount,
    ) {}
}
