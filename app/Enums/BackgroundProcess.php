<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Long-running processes the app depends on (ADR-0015).
 */
enum BackgroundProcess: string
{
    case Scheduler = 'scheduler';
    case QueueWorker = 'queue';

    /**
     * What stops working, in words an operator acts on.
     */
    public function outage(): string
    {
        return match ($this) {
            self::Scheduler => 'The scheduler is not running: lost webhooks are no longer reconciled and heartbeats stopped.',
            self::QueueWorker => 'The queue worker is not processing jobs: new generations are not submitted and webhooks are not processed.',
        };
    }
}
