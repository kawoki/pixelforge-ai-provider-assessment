<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A UTC date range for the spend report: last 30 days by default, at most 92 days
 * (the most GET /v1/usage accepts), ending no later than today.
 */
final class SpendReportRequest extends FormRequest
{
    public const int MAX_DAYS = 92;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'start' => ['sometimes', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:start', 'before_or_equal:'.$this->today()->toDateString(), $this->spanRule()],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $end = $this->date('end', 'Y-m-d', 'UTC')?->toImmutable()->startOfDay() ?? $this->today();
        $start = $this->date('start', 'Y-m-d', 'UTC')?->toImmutable()->startOfDay() ?? $end->subDays(29);

        return [$start, $end];
    }

    private function spanRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            [$start, $end] = $this->range();

            if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
                $fail('The report can cover at most '.self::MAX_DAYS.' days.');
            }
        };
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
