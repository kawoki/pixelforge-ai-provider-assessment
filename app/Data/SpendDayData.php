<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One UTC day of the report, compared with what Pixelforge says it billed that day.
 */
final readonly class SpendDayData
{
    /**
     * @param  list<SpendUserRowData>  $users
     */
    public function __construct(
        public string $date,
        public SpendFiguresData $figures,
        public array $users,
        public ?ProviderUsageDayData $pixelforge,
    ) {}

    /**
     * Null when Pixelforge's numbers are unavailable. Costs must match to the last decimal.
     */
    public function matchesPixelforge(): ?bool
    {
        return $this->pixelforge === null ? null
            : $this->pixelforge->cost->equals($this->figures->cost) && $this->pixelforge->generations === $this->figures->generations;
    }
}
