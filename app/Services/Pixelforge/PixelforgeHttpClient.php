<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Contracts\PixelforgeClient;
use App\Data\GenerationRequestData;
use App\Data\PricingCatalogData;
use App\Data\ProviderGenerationData;
use App\Data\ProviderUsageData;
use App\Exceptions\Pixelforge\PixelforgeNotConfiguredException;
use App\Exceptions\Pixelforge\ProviderTimeoutException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP implementation of the Pixelforge API (PIXELFORGE_API.md, ADR-0005).
 *
 * Makes exactly one attempt per call (retry policy belongs to the jobs) and never logs the API key.
 * Sends only documented parameters: Pixelforge rejects unknown ones with 422.
 */
final readonly class PixelforgeHttpClient implements PixelforgeClient
{
    /**
     * @param  array{timeout_seconds: int, connect_timeout_seconds: int}  $http
     */
    public function __construct(
        private GenerationPayloadMapper $generations,
        private PricingPayloadMapper $pricing,
        private UsagePayloadMapper $usage,
        private ProviderErrorMapper $errors,
        #[Config('pixelforge.base_url')] private ?string $baseUrl,
        #[Config('pixelforge.api_key')] private ?string $apiKey,
        #[Config('pixelforge.http')] private array $http,
    ) {}

    public function createGeneration(GenerationRequestData $request, string $clientReference): ProviderGenerationData
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->withHeaders(['Idempotency-Key' => $clientReference])
            ->post('generations', $this->body($request, $clientReference)));

        return $this->generations->map($response->json());
    }

    public function getGeneration(string $providerGenerationId): ProviderGenerationData
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http
            ->get('generations/'.rawurlencode($providerGenerationId)));

        return $this->generations->map($response->json());
    }

    public function getPricing(): PricingCatalogData
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('pricing'), authenticated: false);

        return $this->pricing->map($response->json());
    }

    public function getUsage(CarbonImmutable $startDate, CarbonImmutable $endDate): ProviderUsageData
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('usage', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]));

        return $this->usage->map($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(GenerationRequestData $request, string $clientReference): array
    {
        return array_filter([
            'prompt' => $request->prompt,
            'size' => $request->size,
            'quality' => $request->quality,
            'reference_images' => $request->referenceImageUrls,
            'metadata' => ['client_reference' => $clientReference],
        ], fn (mixed $value): bool => $value !== []);
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call, bool $authenticated = true): Response
    {
        try {
            $response = $call($this->request($authenticated));
        } catch (ConnectionException $e) {
            throw new ProviderTimeoutException('Could not reach Pixelforge: '.$e->getMessage(), previous: $e);
        }

        return $response->successful() ? $response : throw $this->errors->fromResponse($response);
    }

    private function request(bool $authenticated): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl ?: throw PixelforgeNotConfiguredException::missing('PIXELFORGE_BASE_URL'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->http['timeout_seconds'])
            ->connectTimeout($this->http['connect_timeout_seconds']);

        return $authenticated
            ? $request->withToken($this->apiKey ?: throw PixelforgeNotConfiguredException::missing('PIXELFORGE_API_KEY'))
            : $request;
    }
}
