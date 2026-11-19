<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Data\SpendReportData;
use App\Services\Reports\ProviderUsageService;
use App\Services\Reports\SpendReportAssembler;
use App\Services\Reports\SpendReportQuery;
use Carbon\CarbonImmutable;

/**
 * The operators' report (ADR-0012): what users were charged, what Pixelforge charged us and the
 * profit, per UTC day and per user, with each day checked against Pixelforge's GET /v1/usage.
 */
final readonly class BuildSpendReportAction
{
    public function __construct(
        private SpendReportQuery $query,
        private ProviderUsageService $usage,
        private SpendReportAssembler $assembler,
    ) {}

    public function execute(CarbonImmutable $start, CarbonImmutable $end): SpendReportData
    {
        return $this->assembler->assemble($start, $end, $this->query->rows($start, $end), $this->usage->forRange($start, $end));
    }
}
