<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Data\ProviderUsageData;
use App\Data\ProviderUsageDayData;
use App\Support\Money\Usd;
use Throwable;

/**
 * Translates GET /v1/usage (PIXELFORGE_API.md → Billed usage) into ProviderUsageData.
 * `cost_usd` is an exact 9-decimal string; it is never parsed as a float.
 */
final readonly class UsagePayloadMapper
{
    public function map(mixed $payload): ProviderUsageData
    {
        $data = PayloadReader::of($payload, 'usage')->child('data');

        return new ProviderUsageData(array_map(
            fn (string $index): ProviderUsageDayData => $this->day($data->child($index)),
            $data->keys(),
        ));
    }

    private function day(PayloadReader $day): ProviderUsageDayData
    {
        $date = $day->string('date');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $day->fail('date', 'not YYYY-MM-DD');
        }

        return new ProviderUsageDayData($date, $day->int('generations'), $this->cost($day));
    }

    private function cost(PayloadReader $day): Usd
    {
        $cost = $day->string('cost_usd');

        try {
            return Usd::of($cost);
        } catch (Throwable) {
            return $day->fail('cost_usd', 'not an exact decimal string');
        }
    }
}
