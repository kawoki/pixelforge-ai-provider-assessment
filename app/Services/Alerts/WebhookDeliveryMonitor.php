<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\AlertType;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Notices webhook misconfiguration before it costs money (ADR-0015):
 *
 * - many rejected signatures       → the webhook secret is wrong
 * - generations finished only by polling, with no webhook ever received → the webhook URL is wrong or missing
 *   (exactly what happened on 2026-11-19 when the secret was set but the URL was not)
 */
final readonly class WebhookDeliveryMonitor
{
    /**
     * @param  array{window_minutes: int, rejected_webhooks_threshold: int, missed_webhooks_threshold: int}  $config
     */
    public function __construct(
        private AdminAlertService $alerts,
        private Cache $cache,
        #[Config('monitoring.alerts')] private array $config,
    ) {}

    public function recordRejectedSignature(): void
    {
        $count = $this->count('webhooks:rejected');

        if ($count >= $this->config['rejected_webhooks_threshold']) {
            $this->alerts->raise(AlertType::WebhookSignaturesRejected, "{$count} webhook deliveries were rejected for an invalid signature in the last {$this->config['window_minutes']} minutes.");
        }
    }

    /**
     * Reconciliation just finished this generation. Count it only if no webhook ever
     * arrived for it: events that arrived but sit unprocessed point at the queue instead.
     */
    public function recordFinishedByPolling(Generation $generation): void
    {
        if (WebhookEvent::query()->where('provider_generation_id', $generation->provider_generation_id)->exists()) {
            return;
        }

        $count = $this->count('webhooks:missed');

        if ($count >= $this->config['missed_webhooks_threshold']) {
            $this->alerts->raise(AlertType::WebhooksNotArriving, "{$count} generations finished without a single webhook from Pixelforge in the last {$this->config['window_minutes']} minutes.");
        }
    }

    /**
     * A genuine, verified webhook was processed: delivery works again.
     */
    public function recordDelivered(): void
    {
        $this->cache->forget('webhooks:rejected');
        $this->cache->forget('webhooks:missed');
        $this->alerts->resolve(AlertType::WebhookSignaturesRejected, 'Signed webhooks are being accepted again.');
        $this->alerts->resolve(AlertType::WebhooksNotArriving, 'Webhooks from Pixelforge are arriving again.');
    }

    private function count(string $key): int
    {
        $this->cache->add($key, 0, now()->addMinutes($this->config['window_minutes']));

        return (int) $this->cache->increment($key);
    }
}
