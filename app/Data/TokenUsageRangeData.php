<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The fewest and most tokens a generation can use, known before it runs.
 */
final readonly class TokenUsageRangeData
{
    public function __construct(
        public TokenUsageData $min,
        public TokenUsageData $max,
    ) {}
}
