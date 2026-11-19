<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Actions\Generations\ApplyProviderUpdateAction;
use App\Exceptions\Generations\GenerationNotYetKnownException;
use App\Models\WebhookEvent;
use App\Services\Alerts\WebhookDeliveryMonitor;
use App\Services\Pixelforge\GenerationPayloadMapper;
use Illuminate\Support\Arr;

/**
 * Act on a stored webhook event (ADR-0007). `data.object` is the full generation object,
 * so the generation's state (not the event type) decides what happens.
 * `ping` and unknown event types are acknowledged and ignored.
 */
final readonly class ProcessWebhookEventAction
{
    private const string GENERATION_EVENT_PREFIX = 'generation.';

    public function __construct(
        private GenerationPayloadMapper $generations,
        private ApplyProviderUpdateAction $applyUpdate,
        private WebhookDeliveryMonitor $monitor,
    ) {}

    /**
     * @throws GenerationNotYetKnownException
     */
    public function execute(WebhookEvent $event): void
    {
        if ($event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');

        if (str_starts_with($event->type, self::GENERATION_EVENT_PREFIX)) {
            $this->applyUpdate->execute($this->generations->map(Arr::get($event->payload, 'data.object')));
        }

        $event->forceFill(['processed_at' => now(), 'processing_error' => null])->save();
        $this->monitor->recordDelivered();
    }
}
