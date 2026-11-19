<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Exceptions\Pixelforge\ConcurrencyLimitException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Exceptions\Pixelforge\RateLimitedException;
use App\Models\Generation;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * When and how long to wait before re-submitting after a retryable Pixelforge error (ADR-0005).
 *
 * - concurrency_limit_exceeded: wait as told (Retry-After 5 s) for the whole retry window. A slot
 *   frees as soon as one of the account's 3 running generations finishes.
 * - overloaded / 5xx / rate limited: back off further each time, honouring Retry-After, and give up
 *   after a few attempts ("cap retries", PIXELFORGE_API.md). Pixelforge created nothing, so giving up is safe.
 * - timeouts: never capped. Pixelforge may have accepted the request; only another attempt with the
 *   same Idempotency-Key can tell, so we keep asking until the retry window closes.
 *
 * Up to 25% jitter spreads out jobs that were all told "retry in 5 s".
 */
final readonly class SubmissionRetryPolicy
{
    /**
     * @param  list<int>  $backoff
     */
    public function __construct(
        private Cache $cache,
        #[Config('pixelforge.submission.backoff_seconds')] private array $backoff,
        #[Config('pixelforge.submission.max_attempts_when_unavailable')] private int $maxAttemptsWhenUnavailable,
    ) {}

    public function delaySeconds(PixelforgeException $exception, int $attempt): int
    {
        $base = $exception instanceof ConcurrencyLimitException
            ? $exception->retryAfterSeconds() ?? $this->backoffFor($attempt)
            : max($exception->retryAfterSeconds() ?? 0, $this->backoffFor($attempt));

        return $base + random_int(0, intdiv($base, 4));
    }

    /**
     * Records this failure; true once Pixelforge has refused this generation too many times.
     */
    public function exhausted(Generation $generation, PixelforgeException $exception): bool
    {
        if (! $this->nothingWasCreated($exception)) {
            return false;
        }

        $counter = "submission-refusals:{$generation->id}";
        $this->cache->add($counter, 0, now()->addHour());

        return (int) $this->cache->increment($counter) >= $this->maxAttemptsWhenUnavailable;
    }

    public function maxAttemptsWhenUnavailable(): int
    {
        return $this->maxAttemptsWhenUnavailable;
    }

    /**
     * Errors after which Pixelforge guarantees no generation exists (and none is billed).
     */
    private function nothingWasCreated(PixelforgeException $exception): bool
    {
        return $exception instanceof ProviderUnavailableException || $exception instanceof RateLimitedException;
    }

    private function backoffFor(int $attempt): int
    {
        $index = min(max($attempt, 1), count($this->backoff)) - 1;

        return $this->backoff[$index];
    }
}
