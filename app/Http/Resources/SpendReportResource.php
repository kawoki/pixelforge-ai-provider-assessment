<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\SpendDayData;
use App\Data\SpendFiguresData;
use App\Data\SpendReportData;
use App\Data\SpendUserRowData;
use App\Support\Money\Usd;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Costs are shown with 9 decimals, like Pixelforge's `cost_usd`, so a mismatch is visible to the digit.
 *
 * @property SpendReportData $resource
 */
final class SpendReportResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'start' => $report->start->toDateString(),
            'end' => $report->end->toDateString(),
            'total' => $this->figures($report->total),
            'pixelforge' => ['available' => $report->pixelforgeAvailable, 'total' => $this->money($report->pixelforgeTotal()), 'mismatched_days' => $report->mismatchedDays()],
            'days' => array_map($this->day(...), $report->days),
            'users' => array_map($this->user(...), $report->users),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function day(SpendDayData $day): array
    {
        return [
            'date' => $day->date,
            ...$this->figures($day->figures),
            'pixelforge' => $day->pixelforge === null ? null : ['generations' => $day->pixelforge->generations, 'cost' => $this->money($day->pixelforge->cost)],
            'matches' => $day->matchesPixelforge(),
            'users' => array_map($this->user(...), $day->users),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(SpendUserRowData $row): array
    {
        return ['id' => $row->userId, 'name' => $row->userName, 'email' => $row->userEmail, ...$this->figures($row->figures)];
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(SpendFiguresData $figures): array
    {
        return [
            'generations' => $figures->generations,
            'charged' => $this->money($figures->charged),
            'cost' => $this->money($figures->cost),
            'profit' => $this->money($figures->profit()),
        ];
    }

    /**
     * @return array{amount: string, formatted: string}|null
     */
    private function money(?Usd $amount): ?array
    {
        return MoneyPresenter::present($amount, 9);
    }
}
