<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Pixelforge's billing for a date range, per UTC completion day (GET /v1/usage).
 */
final readonly class ProviderUsageData
{
    /**
     * @param  list<ProviderUsageDayData>  $days
     */
    public function __construct(public array $days) {}

    public function forDate(string $date): ?ProviderUsageDayData
    {
        foreach ($this->days as $day) {
            if ($day->date === $date) {
                return $day;
            }
        }

        return null;
    }

    /**
     * @return list<array{date: string, generations: int, cost: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (ProviderUsageDayData $day): array => $day->toArray(), $this->days);
    }

    /**
     * @param  list<array{date: string, generations: int, cost: string}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(ProviderUsageDayData::fromArray(...), $data));
    }
}
