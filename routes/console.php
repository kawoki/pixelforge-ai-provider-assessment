<?php

use App\Enums\BackgroundProcess;
use App\Jobs\RecordQueueHeartbeatJob;
use App\Models\WebhookEvent;
use App\Services\Alerts\AdminAlertLog;
use App\Services\Health\HeartbeatService;
use Illuminate\Support\Facades\Schedule;

// Requires the scheduler in every environment: Laravel Cloud runs it; locally `composer dev` starts `schedule:work`.

// Polling fallback for lost webhooks (ADR-0010).
Schedule::command('pixelforge:reconcile')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// Heartbeats (ADR-0015): the scheduler beats itself, and dispatches a job the queue worker beats with.
Schedule::call(fn (HeartbeatService $heartbeats) => $heartbeats->beat(BackgroundProcess::Scheduler))
    ->everyMinute()
    ->name('heartbeat:scheduler')
    ->onOneServer();

Schedule::job(new RecordQueueHeartbeatJob)
    ->everyMinute()
    ->name('heartbeat:queue')
    ->onOneServer();

// Housekeeping: processed webhook events and old alert log entries (F-13, ADR-0015).
Schedule::command('model:prune', ['--model' => [WebhookEvent::class]])
    ->daily()
    ->onOneServer();

Schedule::call(fn (AdminAlertLog $log) => $log->prune((int) config('monitoring.retention.alert_log_days')))
    ->daily()
    ->name('prune:admin-alert-logs')
    ->onOneServer();
