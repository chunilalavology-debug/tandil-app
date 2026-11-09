<?php

namespace App\Mail;

use App\Models\AccountDeletionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountDeletionRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AccountDeletionRequest $deletionRequest
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Account deletion request '.$this->deletionRequest->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-deletion-request',
            with: [
                'deletionRequest' => $this->deletionRequest,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
