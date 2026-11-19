<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Enums\BackgroundProcess;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Alerts\AdminAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Mail::fake());

it('is for operators only', function () {
    $this->get(route('admin.system'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.system'))->assertForbidden();
});

it('shows process health and what is waiting, while alerts are still recorded', function () {
    backgroundIsDown(BackgroundProcess::QueueWorker);
    WebhookEvent::factory()->create();
    app(AdminAlertService::class)->raise(AlertType::DailyLimitReached, 'Pixelforge answered daily_limit_exceeded.');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.system'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/SystemStatus')
            ->where('health.degraded', true)
            ->where('health.queue.running', false)
            ->where('health.scheduler.running', true)
            ->where('counts.unprocessed_webhooks', 1)
            ->missing('openAlerts')
            ->missing('log'));

    // the daily limit, and background processing (noticed by this very page view)
    expect(DB::table('admin_alert_logs')->pluck('type')->all())->toEqualCanonicalizing([AlertType::DailyLimitReached->value, AlertType::BackgroundProcessing->value]);
});
