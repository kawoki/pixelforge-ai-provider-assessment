<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\PixelforgeClient;
use App\Data\GenerationRequestData;
use App\Data\PricingCatalogData;
use App\Data\ProviderGenerationData;
use App\Data\ProviderUsageData;
use App\Data\ProviderUsageDayData;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PricingPayloadMapper;
use App\Support\Money\Usd;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriodImmutable;
use InvalidArgumentException;

/**
 * In-memory Pixelforge for tests (ADR-0014).
 *
 * Behaves like the real API where it matters for money: submitting the same
 * client reference twice returns the existing generation (idempotent replay),
 * and every generation carries the rates it was created with.
 */
final class FakePixelforgeClient implements PixelforgeClient
{
    /** Documented rates, USD per 1M tokens, keyed by TokenType value. */
    public const array RATES = ['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50'];

    /** @var list<array{request: GenerationRequestData, clientReference: string}> */
    public array $submissions = [];

    /** @var list<string> provider ids passed to getGeneration(), in order */
    public array $retrievals = [];

    /** @var list<PixelforgeException> */
    private array $createFailures = [];

    /** @var array<string, ProviderGenerationData> keyed by client reference */
    private array $created = [];

    /** @var array<string, ProviderGenerationData|PixelforgeException> keyed by provider id */
    private array $generations = [];

    private PricingCatalogData|PixelforgeException|null $pricing = null;

    private ProviderUsageData|PixelforgeException|null $usage = null;

    /** @var list<array{0: string, 1: string}> date ranges passed to getUsage() */
    public array $usageRequests = [];

    /**
     * The next create calls throw these, in order, before succeeding.
     */
    public function failNextCreateWith(PixelforgeException ...$exceptions): self
    {
        array_push($this->createFailures, ...$exceptions);

        return $this;
    }

    /**
     * What getGeneration() returns (or throws) for this provider id.
     */
    public function setGeneration(ProviderGenerationData|PixelforgeException $result, ?string $providerGenerationId = null): self
    {
        $id = $providerGenerationId ?? ($result instanceof ProviderGenerationData ? $result->providerGenerationId : null);
        $this->generations[$id ?? throw new InvalidArgumentException('Provider id required for an exception result.')] = $result;

        return $this;
    }

    /**
     * What getPricing() returns (or throws). Defaults to the documented pricing in config.
     */
    public function setPricing(PricingCatalogData|PixelforgeException $pricing): self
    {
        $this->pricing = $pricing;

        return $this;
    }

    /**
     * What getUsage() returns (or throws). Defaults to zero usage on every day asked for.
     */
    public function setUsage(ProviderUsageData|PixelforgeException $usage): self
    {
        $this->usage = $usage;

        return $this;
    }

    /**
     * Distinct generations Pixelforge would bill us for.
     */
    public function billableGenerationCount(): int
    {
        return count($this->created);
    }

    public function createGeneration(GenerationRequestData $request, string $clientReference): ProviderGenerationData
    {
        $this->submissions[] = ['request' => $request, 'clientReference' => $clientReference];

        if ($this->createFailures !== []) {
            throw array_shift($this->createFailures);
        }

        return $this->created[$clientReference] ??= $this->queued($clientReference);
    }

    public function getGeneration(string $providerGenerationId): ProviderGenerationData
    {
        $this->retrievals[] = $providerGenerationId;
        $result = $this->generations[$providerGenerationId]
            ?? throw new GenerationNotFoundException("No generation [{$providerGenerationId}].");

        return $result instanceof PixelforgeException ? throw $result : $result;
    }

    public function getPricing(): PricingCatalogData
    {
        $pricing = $this->pricing ?? (new PricingPayloadMapper)->map(config('pixelforge.pricing.fallback'));

        return $pricing instanceof PixelforgeException ? throw $pricing : $pricing;
    }

    public function getUsage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsageData
    {
        $this->usageRequests[] = [$startDate->toDateString(), $endDate->toDateString()];
        $usage = $this->usage ?? new ProviderUsageData(array_map(
            fn (CarbonImmutable $day): ProviderUsageDayData => new ProviderUsageDayData($day->toDateString(), 0, Usd::zero()),
            array_values(iterator_to_array(CarbonPeriodImmutable::create($startDate, $endDate))),
        ));

        return $usage instanceof PixelforgeException ? throw $usage : $usage;
    }

    private function queued(string $clientReference): ProviderGenerationData
    {
        $id = 'gen_'.substr(sha1($clientReference), 0, 16);

        return $this->generations[$id] = new ProviderGenerationData($id, GenerationStatus::Queued, $clientReference, rates: self::RATES);
    }
}
