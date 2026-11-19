<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One user's spend, for a day (date set) or the whole range (date null).
 */
final readonly class SpendUserRowData
{
    public function __construct(
        public ?string $date,
        public int $userId,
        public string $userName,
        public string $userEmail,
        public SpendFiguresData $figures,
    ) {}
}
