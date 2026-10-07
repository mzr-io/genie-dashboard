<?php

namespace App\Modules\Identity\Infrastructure;

use DateTimeInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An invitation email (the first Admin's, or an Admin's invitation of a User or Admin). It carries the single-use link and no password; the link is the only secret. */
final class InvitationMail extends Mailable
{
    public function __construct(
        private readonly string $link,
        private readonly string $workspaceName,
        private readonly DateTimeInterface $expiresAt,
        private readonly string $role = 'admin',
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
            'roleName' => $this->role === 'user' ? 'a User' : 'an Admin',
        ]);
    }
}
