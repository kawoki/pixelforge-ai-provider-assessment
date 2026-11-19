<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ShowSystemStatusAction;
use App\Data\BackgroundHealthData;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operators only (Gate `viewAdminReports`, applied on the route group).
 */
final class SystemStatusController extends Controller
{
    public function __invoke(ShowSystemStatusAction $show): Response
    {
        $status = $show->execute();

        return Inertia::render('admin/SystemStatus', [
            'health' => $this->health($status['health'], $status['heartbeats']),
            'counts' => $status['counts'],
        ]);
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $beats
     * @return array<string, mixed>
     */
    private function health(BackgroundHealthData $health, array $beats): array
    {
        return [
            'degraded' => $health->degraded(),
            'scheduler' => ['running' => $health->schedulerRunning, 'last_beat' => $beats['scheduler']?->toIso8601String()],
            'queue' => ['running' => $health->queueRunning, 'last_beat' => $beats['queue']?->toIso8601String()],
        ];
    }
}
