<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situations that silently lose revenue or users unless an operator acts (ADR-0015).
 */
enum AlertType: string
{
    case BackgroundProcessing = 'background_processing';
    case WebhookSignaturesRejected = 'webhook_signatures_rejected';
    case WebhooksNotArriving = 'webhooks_not_arriving';
    case ProviderKeyRejected = 'provider_key_rejected';
    case DailyLimitReached = 'daily_limit_reached';
    case JobFailed = 'job_failed';
    case AbandonedGenerationRan = 'abandoned_generation_ran';
    case GenerationLostAtProvider = 'generation_lost_at_provider';
    case PricingChanged = 'pricing_changed';

    public function title(): string
    {
        return match ($this) {
            self::BackgroundProcessing => 'Background processing has stopped',
            self::WebhookSignaturesRejected => 'Pixelforge webhooks are failing signature checks',
            self::WebhooksNotArriving => 'Pixelforge webhooks are not arriving',
            self::ProviderKeyRejected => 'Pixelforge rejected our API key',
            self::DailyLimitReached => 'Pixelforge daily generation limit reached',
            self::JobFailed => 'A background job failed permanently',
            self::AbandonedGenerationRan => 'Pixelforge ran a generation we had given up on',
            self::GenerationLostAtProvider => 'Pixelforge has no record of a generation it accepted',
            self::PricingChanged => 'Pixelforge changed its published pricing',
        };
    }

    /**
     * The first thing to check.
     */
    public function action(): string
    {
        return match ($this) {
            self::BackgroundProcessing => 'Check the queue worker and scheduler (Laravel Cloud: worker and scheduler status; locally: composer dev). Users are served by the fallback meanwhile.',
            self::WebhookSignaturesRejected => 'Make sure PIXELFORGE_WEBHOOK_SECRET is the signing secret of the API key this environment uses.',
            self::WebhooksNotArriving => 'Check the webhook URL of this environment\'s API key in the Pixelforge dashboard. Polling is settling generations meanwhile, a few minutes late.',
            self::ProviderKeyRejected => 'Every new generation fails until PIXELFORGE_API_KEY is fixed.',
            self::DailyLimitReached => 'New generations fail until 00:00 UTC (500 per account per day).',
            self::JobFailed => 'Check failed_jobs (php artisan queue:failed) and the logs.',
            self::AbandonedGenerationRan => 'Pixelforge billed us for it, but the user was told it failed for free. The spend report will show the day as mismatched; decide whether to absorb the cost.',
            self::GenerationLostAtProvider => 'It was failed for free. If several appear at once, check PIXELFORGE_API_KEY is the key these generations were created with.',
            self::PricingChanged => 'Charges are unaffected (each generation keeps its own rates), but update the fallback copy in config/pixelforge.php.',
        };
    }
}
