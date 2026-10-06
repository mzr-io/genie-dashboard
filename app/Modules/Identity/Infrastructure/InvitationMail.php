<?php

namespace App\Modules\Identity\Infrastructure;

use DateTimeInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The first-Admin invitation email. It carries the single-use link and no password; the link is the only secret. */
final class InvitationMail extends Mailable
{
    public function __construct(
        private readonly string $link,
        private readonly string $workspaceName,
        private readonly DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to {$this->workspaceName} on Dashflow");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation', with: [
            'link' => $this->link,
            'workspaceName' => $this->workspaceName,
            'expiresAt' => $this->expiresAt,
        ]);
    }
}
