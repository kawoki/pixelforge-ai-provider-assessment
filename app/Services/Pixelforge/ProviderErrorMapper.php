<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Exceptions\Pixelforge\ConcurrencyLimitException;
use App\Exceptions\Pixelforge\DailyLimitExceededException;
use App\Exceptions\Pixelforge\GenerationNotFoundException;
use App\Exceptions\Pixelforge\IdempotencyKeyReusedException;
use App\Exceptions\Pixelforge\InvalidRequestException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\ProviderAuthException;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Exceptions\Pixelforge\RateLimitedException;
use App\Exceptions\Pixelforge\UnexpectedProviderResponseException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Turns a failed Pixelforge response into a typed exception (ADR-0005).
 *
 * Branches on `error.code` as the docs require (PIXELFORGE_API.md → Errors); the HTTP
 * status is only a fallback for codes we don't know yet.
 */
final readonly class ProviderErrorMapper
{
    /** @var array<string, class-string<PixelforgeException>> */
    private const array BY_CODE = [
        'invalid_api_key' => ProviderAuthException::class,
        'validation_failed' => InvalidRequestException::class,
        'content_policy_violation' => InvalidRequestException::class,
        'date_range_too_long' => InvalidRequestException::class,
        'not_found' => GenerationNotFoundException::class,
        'idempotency_key_reused' => IdempotencyKeyReusedException::class,
        'rate_limit_exceeded' => RateLimitedException::class,
        'concurrency_limit_exceeded' => ConcurrencyLimitException::class,
        'daily_limit_exceeded' => DailyLimitExceededException::class,
        'overloaded' => ProviderUnavailableException::class,
    ];

    public function fromResponse(Response $response): PixelforgeException
    {
        $code = $this->stringField($response, 'error.code');
        $class = self::BY_CODE[$code] ?? $this->classForStatus($response->status());

        return new $class($this->message($response), $this->retryAfterSeconds($response), $code);
    }

    /**
     * @return class-string<PixelforgeException>
     */
    private function classForStatus(int $status): string
    {
        return match (true) {
            $status === 429 => RateLimitedException::class,
            $status >= 500 => ProviderUnavailableException::class,
            $status === 401, $status === 403 => ProviderAuthException::class,
            $status === 404 => GenerationNotFoundException::class,
            $status === 400, $status === 422 => InvalidRequestException::class,
            default => UnexpectedProviderResponseException::class,
        };
    }

    private function message(Response $response): string
    {
        return $this->stringField($response, 'error.message') ?? "Pixelforge returned HTTP {$response->status()}.";
    }

    /**
     * Retry-After is documented as seconds; an HTTP date (RFC 9110) is accepted defensively.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        return ctype_digit($header) ? (int) $header : $this->secondsUntil($header);
    }

    private function secondsUntil(string $httpDate): ?int
    {
        try {
            $seconds = CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($httpDate), false);
        } catch (Throwable) {
            return null;
        }

        return max(0, (int) ceil($seconds));
    }

    private function stringField(Response $response, string $key): ?string
    {
        $value = $response->json($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
