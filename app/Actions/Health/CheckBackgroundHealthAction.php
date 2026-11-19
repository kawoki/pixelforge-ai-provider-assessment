<?php

declare(strict_types=1);

namespace App\Actions\Health;

use App\Data\BackgroundHealthData;
use App\Enums\AlertType;
use App\Services\Alerts\AdminAlertService;
use App\Services\Health\BackgroundHealthService;

/**
 * Check the background processes and alert (or stand down) accordingly (ADR-0015).
 *
 * Runs on page loads, on the History page's polls and on /health/background, so an
 * outage is noticed even when the scheduler itself is what stopped.
 */
final readonly class CheckBackgroundHealthAction
{
    public function __construct(
        private BackgroundHealthService $health,
        private AdminAlertService $alerts,
    ) {}

    public function execute(): BackgroundHealthData
    {
        $health = $this->health->check();

        $health->degraded()
            ? $this->alerts->raise(AlertType::BackgroundProcessing, $health->summary(), $health->toArray())
            : $this->alerts->resolve(AlertType::BackgroundProcessing, 'The scheduler and queue worker are running again.');

        return $health;
    }
}
