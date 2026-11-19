<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Exceptions\Pixelforge\DailyLimitExceededException;
use App\Exceptions\Pixelforge\InvalidRequestException;
use App\Exceptions\Pixelforge\PixelforgeException;

/**
 * What users read when a generation fails ("what went wrong": a grading criterion).
 * Every message says, once, that they were not charged, because failed generations never are.
 */
final readonly class FailureMessages
{
    public const string PROVIDER_BUSY_CODE = 'provider_busy';

    public const string PROVIDER_UNAVAILABLE_CODE = 'provider_unavailable';

    public function forRejectedSubmission(PixelforgeException $exception): string
    {
        return match (true) {
            // Pixelforge says "our content policy", which reads as if *we* rejected it.
            $exception->providerCode() === 'content_policy_violation' => 'Pixelforge\'s content policy doesn\'t allow this prompt. Try rewording it. You were not charged.',
            // Pixelforge's validation wording (e.g. which field) is safe and most useful.
            $exception instanceof InvalidRequestException => self::notCharged($exception->getMessage()),
            $exception instanceof DailyLimitExceededException => 'Pixelforge\'s daily generation limit has been reached. Please try again after midnight UTC. You were not charged.',
            default => 'We could not submit this generation because of a problem on our side. You were not charged.',
        };
    }

    /**
     * Pixelforge accepted the generation but it failed while processing (never billed).
     */
    public function forFailedGeneration(?string $code, ?string $providerMessage): string
    {
        return match ($code) {
            'generation_failed' => 'Pixelforge couldn\'t produce an image for this prompt. Try rewording it. You were not charged.',
            'internal_error' => 'Pixelforge had a problem while generating this image. You were not charged. Please try again.',
            default => self::notCharged($providerMessage ?? 'The generation failed at Pixelforge.'),
        };
    }

    /**
     * Pixelforge kept answering "overloaded" (or failing) on every attempt.
     */
    public function providerUnavailable(int $attempts): string
    {
        return "Pixelforge is overloaded right now and didn't accept this generation after {$attempts} tries. You were not charged. Please try again in a minute.";
    }

    public function lostAtProvider(): string
    {
        return 'Pixelforge lost track of this generation. You were not charged. Please try again.';
    }

    /**
     * The retry window closed while waiting for a free slot (3 generations at a time per account).
     */
    public function providerBusy(): string
    {
        return 'Pixelforge was too busy to accept this generation. You were not charged. Please try again.';
    }

    /**
     * Pixelforge's own messages sometimes already say it ("You were not charged; please retry.").
     */
    private static function notCharged(string $message): string
    {
        $message = rtrim($message);

        return str_contains(strtolower($message), 'not charged') ? $message : "{$message} You were not charged.";
    }
}
