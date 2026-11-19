<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Billable token categories, as published by Pixelforge (PIXELFORGE_API.md → Pricing).
 * Adding a type here requires Pixelforge to publish a rate for it.
 */
enum TokenType: string
{
    case InputText = 'input_text';
    case InputImage = 'input_image';
    case Output = 'output';
    case Reasoning = 'reasoning';

    /**
     * The key Pixelforge uses for this type in `rates`, `usage` and `/v1/usage`.
     */
    public function providerKey(): string
    {
        return $this->value.'_tokens';
    }

    /**
     * What users see in a price breakdown.
     */
    public function label(): string
    {
        return match ($this) {
            self::InputText => 'Prompt text',
            self::InputImage => 'Reference images',
            self::Output => 'Image output',
            self::Reasoning => 'Reasoning',
        };
    }

    /**
     * Keys of the given map that are not a known token type.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<array-key>
     */
    public static function unknownKeys(array $map): array
    {
        return array_values(array_filter(
            array_keys($map),
            fn (int|string $key): bool => self::tryFrom((string) $key) === null,
        ));
    }
}
