<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\AbuseFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to whoever is on abuse duty. Deliberately terse and in English: it is read by
 * an operator deciding whether to run `qr:block`, not by a customer.
 */
class AbuseReported extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly AbuseFlag $flag) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[kodeqr] Abuse report: '.($this->flag->reason->value ?? 'unknown'),
            // The reporter's address, when they gave one, so a reply reaches them —
            // but never the From, which would fail SPF and land this in spam.
            replyTo: array_filter([$this->flag->reporter_email]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.abuse-reported');
    }
}
