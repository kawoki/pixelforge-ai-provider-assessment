<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TokenType;
use App\Exceptions\Pricing\InvalidRateCardException;
use App\Support\Money\Usd;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Pixelforge's rates (USD per 1M tokens) plus our markup, as used for one generation (ADR-0004).
 *
 * Snapshotted onto each generation. Pixelforge fixes rates when a generation is created and
 * returns them on the generation object; those are the rates it bills, so they are what we store.
 *
 * Validation guarantees exact money math: a per-token rate is the per-1M rate with the decimal
 * point moved 6 places, then × (1 + markup). So decimals(rate) + 6 + decimals(1 + markup) must fit
 * in Usd::SCALE, and no price ever needs rounding.
 */
final readonly class RateCardData
{
    public const int TOKENS_PER_RATE_UNIT = 1_000_000;

    private const int RATE_UNIT_DECIMALS = 6;

    /**
     * @param  array<string, BigDecimal>  $rates  USD per 1M tokens, keyed by TokenType value
     */
    private function __construct(
        private array $rates,
        public BigDecimal $markup,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  {markup: "0.25", rates: {input_text: "5.00", …}}
     */
    public static function fromArray(array $data): self
    {
        $card = new self(self::rates($data['rates'] ?? null), self::decimal($data['markup'] ?? null, 'markup'));
        $card->assertExactArithmetic();

        return $card;
    }

    /**
     * The same markup with different Pixelforge rates, e.g. the rates fixed on an accepted generation.
     *
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by TokenType value
     */
    public function withRates(array $rates): self
    {
        return self::fromArray(['rates' => $rates, 'markup' => (string) $this->markup]);
    }

    /**
     * USD per 1M tokens of this type, as published, e.g. "12.50".
     */
    public function ratePerMillion(TokenType $type): BigDecimal
    {
        return $this->rates[$type->value];
    }

    /**
     * Exact provider cost of $tokens tokens of this type.
     */
    public function costOf(TokenType $type, int $tokens): Usd
    {
        return Usd::of($this->ratePerMillion($type)->multipliedBy($tokens)->withPointMovedLeft(self::RATE_UNIT_DECIMALS));
    }

    /**
     * The factor applied to provider cost to get the user's price, e.g. 1.25.
     */
    public function multiplier(): BigDecimal
    {
        return $this->markup->plus(1);
    }

    /**
     * @return array{markup: string, rates: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'markup' => (string) $this->markup,
            'rates' => array_map(fn (BigDecimal $rate): string => (string) $rate, $this->rates),
        ];
    }

    /**
     * @return array<string, BigDecimal>
     */
    private static function rates(mixed $rates): array
    {
        if (! is_array($rates) || TokenType::unknownKeys($rates) !== []) {
            throw new InvalidRateCardException('Rates must be a map of known token types.');
        }

        $parsed = [];

        foreach (TokenType::cases() as $type) {
            $parsed[$type->value] = self::decimal($rates[$type->value] ?? null, "rates.{$type->value}");
        }

        return $parsed;
    }

    private static function decimal(mixed $value, string $field): BigDecimal
    {
        if (! is_string($value)) {
            throw new InvalidRateCardException("[{$field}] must be a decimal string (never a float).");
        }

        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException) {
            throw new InvalidRateCardException("[{$field}] is not a valid decimal: [{$value}].");
        }

        return $decimal->isNegative()
            ? throw new InvalidRateCardException("[{$field}] must not be negative.")
            : $decimal;
    }

    private function assertExactArithmetic(): void
    {
        $rateScale = max([0, ...array_values(array_map(self::significantScale(...), $this->rates))]);
        $needed = $rateScale + self::RATE_UNIT_DECIMALS + self::significantScale($this->multiplier());

        if ($needed > Usd::SCALE) {
            throw new InvalidRateCardException(
                "Rates and markup need {$needed} decimal places; at most ".Usd::SCALE.' fit exactly.',
            );
        }
    }

    private static function significantScale(BigDecimal $value): int
    {
        return max(0, $value->strippedOfTrailingZeros()->getScale());
    }
}
