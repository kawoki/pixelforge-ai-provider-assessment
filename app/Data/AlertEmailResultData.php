<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Whether an admin alert email went out.
 */
final readonly class AlertEmailResultData
{
    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string NO_RECIPIENTS = 'no_recipients';

    private function __construct(
        public string $status,
        public int $recipients,
        public ?string $error = null,
    ) {}

    public static function sent(int $recipients): self
    {
        return new self(self::SENT, $recipients);
    }

    public static function failed(int $recipients, string $error): self
    {
        return new self(self::FAILED, $recipients, $error);
    }

    public static function noRecipients(): self
    {
        return new self(self::NO_RECIPIENTS, 0);
    }
}
