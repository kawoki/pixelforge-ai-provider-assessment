<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\BuildSpendReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SpendReportRequest;
use App\Http\Resources\SpendReportResource;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operators only (Gate `viewAdminReports`, applied on the route group).
 */
final class SpendReportController extends Controller
{
    public function __invoke(SpendReportRequest $request, BuildSpendReportAction $build): Response
    {
        [$start, $end] = $request->range();

        return Inertia::render('admin/SpendReport', [
            'report' => new SpendReportResource($build->execute($start, $end)),
        ]);
    }
}
