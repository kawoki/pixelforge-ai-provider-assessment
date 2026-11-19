<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Typed, fail-loud access to a decoded Pixelforge JSON payload.
 * Any shape surprise becomes an UnexpectedProviderResponseException naming the field.
 */
final readonly class PayloadReader
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(private array $data, private string $context) {}

    public static function of(mixed $payload, string $context): self
    {
        return is_array($payload)
            ? new self($payload, $context)
            : throw new UnexpectedProviderResponseException("Unexpected Pixelforge {$context}: not an object.");
    }

    public function has(string $key): bool
    {
        return Arr::get($this->data, $key) !== null;
    }

    public function child(string $key): self
    {
        return self::of(Arr::get($this->data, $key) ?? $this->fail($key, 'missing'), "{$this->context}.{$key}");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->data));
    }

    public function string(string $key): string
    {
        return $this->optionalString($key) ?? $this->fail($key, 'missing');
    }

    public function optionalString(string $key): ?string
    {
        $value = Arr::get($this->data, $key);

        return match (true) {
            $value === null, $value === '' => null,
            is_string($value) => $value,
            default => $this->fail($key, 'not a string'),
        };
    }

    public function int(string $key, int $min = 0): int
    {
        $value = Arr::get($this->data, $key);

        return is_int($value) && $value >= $min ? $value : $this->fail($key, "not an integer >= {$min}");
    }

    public function optionalInt(string $key): ?int
    {
        return $this->has($key) ? $this->int($key) : null;
    }

    /**
     * Accepts JSON numbers or numeric strings. For non-money factors only — money is always a string.
     */
    public function decimal(string $key): BigDecimal
    {
        $value = Arr::get($this->data, $key);

        try {
            return is_int($value) || is_float($value) || is_string($value) ? BigDecimal::of((string) $value) : $this->fail($key, 'not a number');
        } catch (MathException) {
            return $this->fail($key, 'not a number');
        }
    }

    public function optionalTimestamp(string $key): ?CarbonImmutable
    {
        $value = $this->optionalString($key);

        try {
            return $value === null ? null : CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return $this->fail($key, 'not a timestamp');
        }
    }

    public function fail(string $key, string $problem): never
    {
        throw new UnexpectedProviderResponseException("Unexpected Pixelforge {$this->context}: [{$key}] is {$problem}.");
    }
}
