<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Branded plain-text email (follow-ups, meeting confirmations, automatic proposals / invoices). */
class CrmMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int, array{data: string, name: string}> $files */
    public function __construct(
        public string $subjectLine,
        public string $messageBody,
        public array $brand,
        public array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.message');
    }

    public function attachments(): array
    {
        return array_map(
            fn ($f) => Attachment::fromData(fn () => $f['data'], $f['name'])->withMime('application/pdf'),
            $this->files
        );
    }
}
