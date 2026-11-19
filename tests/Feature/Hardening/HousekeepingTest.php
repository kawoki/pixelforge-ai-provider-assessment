<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Alerts\AdminAlertLog;
use App\Services\Alerts\AdminAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

it('prunes processed webhook events after 60 days, but never unprocessed ones', function () {
    $old = WebhookEvent::factory()->create(['processed_at' => now()->subDays(61)]);
    $recent = WebhookEvent::factory()->create(['processed_at' => now()->subDays(5)]);
    $stuck = WebhookEvent::factory()->create(['received_at' => now()->subDays(90)]);

    $this->artisan('model:prune', ['--model' => [WebhookEvent::class]])->assertSuccessful();

    expect(WebhookEvent::query()->pluck('id')->all())->toEqualCanonicalizing([$recent->id, $stuck->id])
        ->and(WebhookEvent::find($old->id))->toBeNull();
});

it('prunes alert log entries older than the retention period', function () {
    DB::table('admin_alert_logs')->insert([
        ['type' => 'job_failed', 'event' => 'opened', 'title' => 't', 'details' => 'old', 'email_status' => 'sent', 'created_at' => now()->subDays(200)],
        ['type' => 'job_failed', 'event' => 'opened', 'title' => 't', 'details' => 'new', 'email_status' => 'sent', 'created_at' => now()->subDays(2)],
    ]);

    app(AdminAlertLog::class)->prune(180);

    expect(DB::table('admin_alert_logs')->pluck('details')->all())->toBe(['new']);
});

describe('the alert log', function () {
    beforeEach(fn () => Mail::fake());

    it('records when a problem opens, is reminded, and is resolved, with the email outcome', function () {
        User::factory()->admin()->create();
        $alerts = app(AdminAlertService::class);

        $alerts->raise(AlertType::JobFailed, 'first');
        $alerts->raise(AlertType::JobFailed, 'repeated within the hour: not logged again');
        $this->travel(61)->minutes();
        $alerts->raise(AlertType::JobFailed, 'still failing');
        $alerts->resolve(AlertType::JobFailed, 'fixed');

        expect(DB::table('admin_alert_logs')->orderBy('id')->pluck('event')->all())->toBe(['opened', 'reminder', 'resolved'])
            ->and(DB::table('admin_alert_logs')->pluck('email_status')->unique()->all())->toBe(['sent']);
    });

    it('records that nobody could be emailed', function () {
        app(AdminAlertService::class)->raise(AlertType::DailyLimitReached, 'details');

        expect(DB::table('admin_alert_logs')->sole())->email_status->toBe('no_recipients')->recipients->toBe(0);
    });

    it('stays out of laravel.log', function () {
        Log::spy();

        app(AdminAlertService::class)->raise(AlertType::DailyLimitReached, 'details');

        Log::shouldNotHaveReceived('critical');
    });
});
