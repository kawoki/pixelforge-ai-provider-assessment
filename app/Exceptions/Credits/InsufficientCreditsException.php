<?php

declare(strict_types=1);

namespace App\Exceptions\Credits;

use App\Support\Money\Usd;
use RuntimeException;

/**
 * The user can't cover the most a generation could cost. The message is written for users.
 */
final class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly Usd $maximumCost, public readonly Usd $available)
    {
        parent::__construct(sprintf(
            'This generation can cost up to %s, but you have %s available. Buy credits to continue.',
            $maximumCost->format(4),
            $available->format(4),
        ));
    }
}
