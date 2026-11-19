<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money\Usd;

/**
 * The one shape money takes on its way to the frontend (ADR-0003): the exact amount as a
 * string, plus a display string. The frontend shows `formatted` and never does arithmetic.
 */
final class MoneyPresenter
{
    /**
     * @param  int<0, max>  $decimals
     * @return ($usd is null ? null : array{amount: string, formatted: string})
     */
    public static function present(?Usd $usd, int $decimals = 4): ?array
    {
        return $usd === null ? null : ['amount' => $usd->toDecimalString(), 'formatted' => $usd->format($decimals)];
    }
}
