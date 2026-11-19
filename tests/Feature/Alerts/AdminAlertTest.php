<?php

declare(strict_types=1);

use App\Actions\Health\CheckBackgroundHealthAction;
use App\Enums\AlertType;
use App\Enums\BackgroundProcess;
use App\Mail\AdminAlertMail;
use App\Models\User;
use App\Services\Alerts\AdminAlertService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['monitoring.alerts.recipients' => ['oncall@example.test']]);
    User::factory()->admin()->create(['email' => 'admin@example.test']);
});

function alerts(): AdminAlertService
{
    return app(AdminAlertService::class);
}

it('emails the admins and the configured addresses', function () {
    alerts()->raise(AlertType::DailyLimitReached, 'details');

    Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo('admin@example.test') && $mail->hasTo('oncall@example.test') && ! $mail->resolved);
});

it('emails once per problem per hour, then reminds', function () {
    alerts()->raise(AlertType::JobFailed, 'first');
    alerts()->raise(AlertType::JobFailed, 'second');
    Mail::assertSentCount(1);

    $this->travel(61)->minutes();
    alerts()->raise(AlertType::JobFailed, 'still happening');
    Mail::assertSentCount(2);
});

it('sends a recovery email only for problems that were open', function () {
    alerts()->resolve(AlertType::BackgroundProcessing, 'fine');
    Mail::assertNothingSent();

    alerts()->raise(AlertType::BackgroundProcessing, 'down');
    alerts()->resolve(AlertType::BackgroundProcessing, 'back');

    Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->resolved);
});

it('never lets a broken mailer break the request, and records the failure', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    alerts()->raise(AlertType::ProviderKeyRejected, 'details');

    expect(DB::table('admin_alert_logs')->sole())
        ->event->toBe('opened')
        ->email_status->toBe('failed')
        ->email_error->toBe('SMTP down');
});

it('writes a useful email', function () {
    $mail = new AdminAlertMail(AlertType::WebhooksNotArriving, '3 generations finished without a webhook.');

    $mail->assertHasSubject('[PixelForge] Pixelforge webhooks are not arriving');
    $mail->assertSeeInText('3 generations finished without a webhook.');
    $mail->assertSeeInText('webhook URL');
});

describe('background processing', function () {
    it('alerts when the queue worker stops, and again when it is back', function () {
        backgroundIsDown(BackgroundProcess::QueueWorker);
        app(CheckBackgroundHealthAction::class)->execute();

        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->type === AlertType::BackgroundProcessing && str_contains($mail->details, 'queue worker'));

        backgroundIsUp();
        app(CheckBackgroundHealthAction::class)->execute();

        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->resolved);
    });

    it('alerts when a job gives up for good', function () {
        $job = new SyncJob(app(), json_encode(['displayName' => 'App\\Jobs\\SubmitGenerationJob', 'job' => 'x', 'data' => []]), 'sync', 'default');

        event(new JobFailed('sync', $job, new RuntimeException('Pixelforge was down for 30 minutes')));

        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->type === AlertType::JobFailed && str_contains($mail->details, 'SubmitGenerationJob'));
    });
});
