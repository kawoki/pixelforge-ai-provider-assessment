<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Data\AlertEmailResultData;
use App\Enums\AlertType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The record of admin alerts (ADR-0015): table `admin_alert_logs`, query builder only, shown on
 * the System status page instead of cluttering laravel.log. If the table can't be written (e.g.
 * the database is the problem), the entry falls back to laravel.log so it is never lost.
 */
final readonly class AdminAlertLog
{
    public const string OPENED = 'opened';

    public const string REMINDER = 'reminder';

    public const string RESOLVED = 'resolved';

    private const string TABLE = 'admin_alert_logs';

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(AlertType $type, string $event, string $details, array $context, AlertEmailResultData $email): void
    {
        try {
            DB::table(self::TABLE)->insert([
                'type' => $type->value, 'event' => $event, 'title' => $type->title(), 'details' => $details,
                'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
                'email_status' => $email->status, 'recipients' => $email->recipients, 'email_error' => $email->error,
            ]);
        } catch (Throwable $e) {
            Log::critical("[{$event}] {$type->title()}: {$details}", [...$context, 'email' => $email->status, 'email_error' => $email->error, 'alert_log_error' => $e->getMessage()]);
        }
    }

    public function prune(int $olderThanDays): int
    {
        return DB::table(self::TABLE)->where('created_at', '<', now()->subDays($olderThanDays))->delete();
    }
}
