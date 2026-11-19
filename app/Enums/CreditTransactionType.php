<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ledger entry types (ADR-0008, ADR-0011).
 */
enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    case Charge = 'charge';
    case Adjustment = 'adjustment';
}
