<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What one reconciliation run did.
 */
final readonly class ReconciliationResultData
{
    public function __construct(
        public int $polled = 0,
        public int $errors = 0,
        public int $requeued = 0,
        public bool $stoppedByRateLimit = false,
    ) {}
}
