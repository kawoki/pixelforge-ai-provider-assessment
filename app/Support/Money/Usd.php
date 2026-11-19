<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Exceptions\Money\MoneyPrecisionExceededException;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use JsonSerializable;
use Stringable;

/**
 * An exact US dollar amount (ADR-0003).
 *
 * Always held at SCALE decimal places. Arithmetic never rounds: a result that
 * cannot be represented exactly throws, so precision loss is never silent.
 * Rounding happens only in format(), for display.
 */
final readonly class Usd implements JsonSerializable, Stringable
{
    public const int SCALE = 12;

    private BigDecimal $amount;

    private function __construct(BigNumber $amount)
    {
        $this->amount = self::exact($amount);
    }

    /**
     * Floats are deliberately not accepted. Use a decimal string.
     */
    public static function of(self|BigNumber|int|string $amount): self
    {
        return $amount instanceof self ? $amount : new self(BigDecimal::of($amount));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function plus(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    public function multipliedBy(BigNumber|int|string $factor): self
    {
        return new self($this->amount->multipliedBy($factor));
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->amount->isGreaterThan($other->amount);
    }

    public function isLessThan(self $other): bool
    {
        return $this->amount->isLessThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    /**
     * Full-precision representation, e.g. "0.042500000000". Used for storage and APIs.
     */
    public function toDecimalString(): string
    {
        return (string) $this->amount;
    }

    /**
     * Human-readable amount, e.g. "$1,234.5678" or "-$0.0425". Rounds half-up.
     *
     * @param  int<0, max>  $decimals
     */
    public function format(int $decimals = 4): string
    {
        $rounded = $this->amount->abs()->toScale($decimals, RoundingMode::HalfUp);
        [$integer, $fraction] = array_pad(explode('.', (string) $rounded), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+$)/', ',', $integer).($fraction === '' ? '' : '.'.$fraction);
        $sign = $this->amount->isNegative() && ! $rounded->isZero() ? '-' : '';

        return $sign.'$'.$grouped;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    private static function exact(BigNumber $amount): BigDecimal
    {
        try {
            return $amount->toScale(self::SCALE, RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw MoneyPrecisionExceededException::for((string) $amount, self::SCALE);
        }
    }
}
