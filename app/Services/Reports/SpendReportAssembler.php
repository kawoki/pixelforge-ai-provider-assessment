<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\ProviderUsageData;
use App\Data\SpendDayData;
use App\Data\SpendFiguresData;
use App\Data\SpendReportData;
use App\Data\SpendUserRowData;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriodImmutable;

/**
 * Turns per-day-per-user rows into the report (ADR-0012). Pure: no database, no HTTP.
 * Every day of the range is present (like /v1/usage, which includes zero days), newest first.
 */
final readonly class SpendReportAssembler
{
    /**
     * @param  list<SpendUserRowData>  $rows
     */
    public function assemble(CarbonImmutable $start, CarbonImmutable $end, array $rows, ?ProviderUsageData $usage): SpendReportData
    {
        $days = array_map(fn (string $date): SpendDayData => $this->day($date, $rows, $usage), $this->dates($start, $end));
        $total = SpendFiguresData::sum(array_map(fn (SpendUserRowData $row): SpendFiguresData => $row->figures, $rows));

        return new SpendReportData($start, $end, $days, $this->perUser($rows), $total, $usage !== null);
    }

    /**
     * @param  list<SpendUserRowData>  $rows
     */
    private function day(string $date, array $rows, ?ProviderUsageData $usage): SpendDayData
    {
        $users = array_values(array_filter($rows, fn (SpendUserRowData $row): bool => $row->date === $date));
        $figures = SpendFiguresData::sum(array_map(fn (SpendUserRowData $row): SpendFiguresData => $row->figures, $users));

        return new SpendDayData($date, $figures, $users, $usage?->forDate($date));
    }

    /**
     * @param  list<SpendUserRowData>  $rows
     * @return list<SpendUserRowData>
     */
    private function perUser(array $rows): array
    {
        $byUser = [];

        foreach ($rows as $row) {
            $previous = $byUser[$row->userId]->figures ?? SpendFiguresData::zero();
            $byUser[$row->userId] = new SpendUserRowData(null, $row->userId, $row->userName, $row->userEmail, $previous->plus($row->figures));
        }

        usort($byUser, fn (SpendUserRowData $a, SpendUserRowData $b): int => $b->figures->charged->toBigDecimal()->compareTo($a->figures->charged->toBigDecimal()));

        return $byUser;
    }

    /**
     * @return list<string> newest first
     */
    private function dates(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = array_map(fn (CarbonImmutable $day): string => $day->toDateString(), iterator_to_array(CarbonPeriodImmutable::create($start, $end)));

        return array_reverse(array_values($dates));
    }
}
