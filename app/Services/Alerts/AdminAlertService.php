<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Data\AlertEmailResultData;
use App\Enums\AlertType;
use App\Mail\AdminAlertMail;
use App\Models\User;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells operators about problems that silently cost revenue or users (ADR-0015).
 *
 * A problem is "open" from its first alert until it is resolved: one email when it opens, a
 * reminder at most hourly while it lasts, one when it is resolved. Each of those is recorded in
 * the alert log (with whether the email went out); the many repeated raise() calls in between
 * (e.g. one per page view while degraded) are not. Emails are sent synchronously, never queued.
 */
final readonly class AdminAlertService
{
    /**
     * @param  array{recipients: list<string>, repeat_after_minutes: int}  $config
     */
    public function __construct(
        private Cache $cache,
        private AdminAlertLog $log,
        #[Config('monitoring.alerts')] private array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function raise(AlertType $type, string $details, array $context = []): void
    {
        if (! $this->cache->add($this->reminderKey($type), true, now()->addMinutes($this->config['repeat_after_minutes']))) {
            return; // already told the admins within the hour
        }

        $event = $this->isOpen($type) ? AdminAlertLog::REMINDER : AdminAlertLog::OPENED;
        $this->cache->forever($this->openKey($type), now()->toIso8601String());
        $this->log->record($type, $event, $details, $context, $this->send(new AdminAlertMail($type, $details)));
    }

    public function resolve(AlertType $type, string $details): void
    {
        if ($this->cache->pull($this->openKey($type)) === null) {
            return;
        }

        $this->cache->forget($this->reminderKey($type));
        $this->log->record($type, AdminAlertLog::RESOLVED, $details, [], $this->send(new AdminAlertMail($type, $details, resolved: true)));
    }

    public function isOpen(AlertType $type): bool
    {
        return $this->cache->has($this->openKey($type));
    }

    private function send(AdminAlertMail $mail): AlertEmailResultData
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return AlertEmailResultData::noRecipients();
        }

        try {
            Mail::to($recipients)->send($mail);
        } catch (Throwable $e) {
            return AlertEmailResultData::failed(count($recipients), $e->getMessage());
        }

        return AlertEmailResultData::sent(count($recipients));
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $admins = User::query()->where('is_admin', true)->pluck('email')->all();

        return array_values(array_unique([...$this->config['recipients'], ...$admins]));
    }

    private function openKey(AlertType $type): string
    {
        return "alert-open:{$type->value}";
    }

    private function reminderKey(AlertType $type): string
    {
        return "alert-reminded:{$type->value}";
    }
}
