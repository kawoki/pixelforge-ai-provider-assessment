<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Usd;

/**
 * What Pixelforge billed our API key for one UTC day (GET /v1/usage).
 */
final readonly class ProviderUsageDayData
{
    public function __construct(
        public string $date,
        public int $generations,
        public Usd $cost,
    ) {}

    /**
     * @return array{date: string, generations: int, cost: string}
     */
    public function toArray(): array
    {
        return ['date' => $this->date, 'generations' => $this->generations, 'cost' => $this->cost->toDecimalString()];
    }

    /**
     * @param  array{date: string, generations: int, cost: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['date'], $data['generations'], Usd::of($data['cost']));
    }
}
