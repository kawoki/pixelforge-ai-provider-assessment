<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TokenType;
use InvalidArgumentException;

/**
 * Token counts per billable token type. Missing types count as zero.
 */
final readonly class TokenUsageData
{
    /**
     * @param  array<string, int>  $counts  keyed by TokenType value
     */
    private function __construct(private array $counts) {}

    /**
     * @param  array<array-key, mixed>  $counts  keyed by TokenType value
     */
    public static function fromArray(array $counts): self
    {
        $normalized = [];

        foreach (TokenType::cases() as $type) {
            $normalized[$type->value] = self::count($counts, $type);
        }

        self::assertNoUnknownTypes($counts);

        return new self($normalized);
    }

    public function tokens(TokenType $type): int
    {
        return $this->counts[$type->value];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }

    /**
     * @param  array<array-key, mixed>  $counts
     */
    private static function count(array $counts, TokenType $type): int
    {
        $value = $counts[$type->value] ?? 0;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Token count for [{$type->value}] must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $counts
     */
    private static function assertNoUnknownTypes(array $counts): void
    {
        $unknown = TokenType::unknownKeys($counts);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown token type(s): ['.implode(', ', $unknown).'].');
        }
    }
}
