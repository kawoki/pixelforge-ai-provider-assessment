<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Data\PricingCatalogData;
use App\Enums\AlertType;
use App\Services\Alerts\AdminAlertService;
use App\Services\Pixelforge\PricingPayloadMapper;
use Illuminate\Container\Attributes\Config;

/**
 * Notices when Pixelforge's published pricing no longer matches the fallback copy in config,
 * which is what estimates use whenever /v1/pricing is unreachable.
 */
final readonly class PricingDriftMonitor
{
    /**
     * @param  array<string, mixed>  $fallback
     */
    public function __construct(
        private AdminAlertService $alerts,
        private PricingPayloadMapper $mapper,
        #[Config('pixelforge.pricing.fallback')] private array $fallback,
    ) {}

    public function check(PricingCatalogData $live): void
    {
        $ours = $this->mapper->map($this->fallback)->toArray();

        if ($live->toArray() == $ours) {
            $this->alerts->resolve(AlertType::PricingChanged, 'The fallback pricing in config matches Pixelforge again.');

            return;
        }

        $this->alerts->raise(AlertType::PricingChanged, 'Published: '.json_encode($live->toArray()['rates']).'; our fallback: '.json_encode($ours['rates']).'.');
    }
}
