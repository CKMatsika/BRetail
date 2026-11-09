<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\TradeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TradeDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TradeDocument $document,
        public Company $company,
        public string $customSubject,
        public ?string $customMessage,
        public string $portalUrl,
        public array $presented,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->customSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trade-document',
        );
    }
}

