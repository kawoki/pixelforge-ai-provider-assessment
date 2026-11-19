<?php

declare(strict_types=1);

namespace App\Exceptions\Money;

use LogicException;

/**
 * Thrown when an amount cannot be represented exactly at the money scale.
 * This is a programming/configuration error (see RateCardData validation), never user input.
 */
final class MoneyPrecisionExceededException extends LogicException
{
    public static function for(string $amount, int $scale): self
    {
        return new self("Amount [{$amount}] cannot be represented exactly with {$scale} decimal places.");
    }
}
