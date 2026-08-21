<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Not queueable, on purpose: the queue runs on the infrastructure the canary just
 * found broken. See CheckRedirectHealth::raiseAlert().
 */
class RedirectCanaryFailed extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $reason,
        public readonly int $failures,
    ) {}

    public function envelope(): Envelope
    {
        // The failure count in the subject so a phone lock screen carries it.
        return new Envelope(subject: "[kodeqr] Redirect DOWN — {$this->failures} consecutive failures");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.redirect-canary-failed');
    }
}
