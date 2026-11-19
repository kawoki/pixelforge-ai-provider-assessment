<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\Money\Usd;

/**
 * Succeeded generations, what users paid for them, and what Pixelforge charged us (ADR-0012).
 */
final readonly class SpendFiguresData
{
    public function __construct(
        public int $generations,
        public Usd $charged,
        public Usd $cost,
    ) {}

    public static function zero(): self
    {
        return new self(0, Usd::zero(), Usd::zero());
    }

    public function plus(self $other): self
    {
        return new self($this->generations + $other->generations, $this->charged->plus($other->charged), $this->cost->plus($other->cost));
    }

    public function profit(): Usd
    {
        return $this->charged->minus($this->cost);
    }

    /**
     * @param  iterable<self>  $figures
     */
    public static function sum(iterable $figures): self
    {
        $total = self::zero();

        foreach ($figures as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }
}
