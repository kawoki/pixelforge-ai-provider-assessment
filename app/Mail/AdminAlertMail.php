<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\AlertType;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent immediately, never queued: the queue may be exactly what is broken (ADR-0015).
 */
final class AdminAlertMail extends Mailable
{
    public function __construct(
        public readonly AlertType $type,
        public readonly string $details,
        public readonly bool $resolved = false,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->resolved ? 'Resolved: ' : '';

        return new Envelope(subject: '['.config('app.name').'] '.$prefix.$this->type->title());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.admin-alert', with: [
            'title' => $this->type->title(),
            'details' => $this->details,
            'action' => $this->resolved ? null : $this->type->action(),
            'resolved' => $this->resolved,
            'environment' => app()->environment(),
            'url' => config('app.url'),
        ]);
    }
}
