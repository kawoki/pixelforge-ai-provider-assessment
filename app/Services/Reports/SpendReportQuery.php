<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\SpendFiguresData;
use App\Data\SpendUserRowData;
use App\Enums\GenerationStatus;
use App\Support\Money\Usd;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Succeeded generations per UTC completion day and user (ADR-0012). Day = the day Pixelforge
 * completed it, which is the day Pixelforge bills it on. Sums are exact: computed by Postgres
 * on NUMERIC and read back as text, never as PHP floats.
 */
final readonly class SpendReportQuery
{
    private const string DAY = "to_char((g.completed_at AT TIME ZONE 'UTC')::date, 'YYYY-MM-DD')";

    /**
     * @return list<SpendUserRowData>
     */
    public function rows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('generations as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.status', GenerationStatus::Succeeded->value)
            ->where('g.completed_at', '>=', $start->startOfDay())
            ->where('g.completed_at', '<', $end->addDay()->startOfDay())
            ->selectRaw(self::DAY.' AS day, g.user_id, u.name, u.email, COUNT(*) AS generations')
            ->selectRaw('SUM(g.actual_user_cost)::text AS charged, SUM(g.actual_provider_cost)::text AS cost')
            ->groupByRaw(self::DAY.', g.user_id, u.name, u.email')
            ->orderByRaw('day DESC, charged DESC')
            ->get();

        return array_values($rows->map(fn (stdClass $row): SpendUserRowData => $this->toRow($row))->all());
    }

    private function toRow(stdClass $row): SpendUserRowData
    {
        $figures = new SpendFiguresData((int) $row->generations, Usd::of($row->charged), Usd::of($row->cost));

        return new SpendUserRowData($row->day, (int) $row->user_id, $row->name, $row->email, $figures);
    }
}
