<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps NUMERIC(24,12) columns to Usd value objects (ADR-0003).
 *
 * Accepts any value on set so it can reject floats at runtime with a clear error.
 *
 * @implements CastsAttributes<Usd|null, mixed>
 */
final class UsdCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Usd
    {
        return $value === null ? null : Usd::of((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Usd || is_string($value) || is_int($value)) {
            return Usd::of($value)->toDecimalString();
        }

        throw new InvalidArgumentException("Money attribute [{$key}] must be Usd, a decimal string or int; got ".get_debug_type($value).'.');
    }
}
