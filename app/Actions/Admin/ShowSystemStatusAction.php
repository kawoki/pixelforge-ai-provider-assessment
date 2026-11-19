<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Health\CheckBackgroundHealthAction;
use App\Enums\BackgroundProcess;
use App\Models\WebhookEvent;
use App\Services\Health\HeartbeatService;
use Illuminate\Support\Facades\DB;

/**
 * What an operator needs at a glance (ADR-0015): are the background processes running, and
 * what is waiting. Alerts themselves are emailed and recorded in `admin_alert_logs`. Viewing it also runs the health
 * check, so an admin looking at it is itself a way outages get noticed.
 */
final readonly class ShowSystemStatusAction
{
    public function __construct(
        private CheckBackgroundHealthAction $health,
        private HeartbeatService $heartbeats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return [
            'health' => $this->health->execute(),
            'heartbeats' => array_combine(
                array_column(BackgroundProcess::cases(), 'value'),
                array_map($this->heartbeats->lastBeat(...), BackgroundProcess::cases()),
            ),
            'counts' => $this->counts(),
        ];
    }

    /**
     * @return array{waiting_jobs: int, failed_jobs: int, unprocessed_webhooks: int}
     */
    private function counts(): array
    {
        return [
            'waiting_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'unprocessed_webhooks' => WebhookEvent::query()->whereNull('processed_at')->count(),
        ];
    }
}
