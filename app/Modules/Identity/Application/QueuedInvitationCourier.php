<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\InvitationCourier;
use App\Modules\Identity\Contracts\InvitationDeliveryFailed;
use App\Modules\Identity\Contracts\InvitationSecret;
use App\Modules\Identity\Infrastructure\InvitationMail;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

/**
 * Holds the invitation emails of the current request until its transaction has committed, then sends them
 * (`SendInvitationsAfterCommit` calls `flush`). A request that fails and rolls back discards them, so there is never
 * a link in a mailbox to an invitation that does not exist. Bound as a singleton: the queue lives for one request.
 */
final class QueuedInvitationCourier implements InvitationCourier
{
    /** @var list<array{id: string, email: string, link: string, workspace: string, role: string, expires: DateTimeInterface}> */
    private array $queue = [];

    /** Whether a flush middleware is running for this request; queuing without one would silently lose the email. */
    private bool $active = false;

    /** The flush middleware calls this when it starts a request, and `discard` when the request ends. */
    public function activate(): void
    {
        $this->queue = [];
        $this->active = true;
    }

    public function newSecret(): InvitationSecret
    {
        $token = InvitationToken::generate();

        return new InvitationSecret($token, InvitationToken::hash($token));
    }

    public function deliverAfterCommit(string $invitationId, string $email, InvitationSecret $secret, string $workspaceName, string $role, DateTimeInterface $expiresAt): void
    {
        if (! $this->active) {
            // Never a silent loss: the invitation exists, but nothing would ever send its link.
            Log::error('identity.invitation.no_flush_middleware', ['invitation_id' => $invitationId]);

            throw new LogicException('An invitation email was queued outside a request that sends it after the commit.');
        }

        $this->queue[] = [
            'id' => $invitationId,
            'email' => $email,
            'link' => url('/invitations/'.$secret->token),
            'workspace' => $workspaceName,
            'role' => $role,
            'expires' => $expiresAt,
        ];
    }

    /** Drops what is queued without sending it (the request failed, or a new one starts). */
    public function discard(): void
    {
        $this->queue = [];
        $this->active = false;
    }

    /**
     * Sends every queued email. A failure is logged without the address or link and does not stop the others.
     *
     * @throws InvitationDeliveryFailed after all were attempted, naming the first invitation that failed
     */
    public function flush(): void
    {
        $queued = $this->queue;
        $this->queue = [];
        $failed = null;

        foreach ($queued as $message) {
            try {
                Mail::to($message['email'])->send(new InvitationMail($message['link'], $message['workspace'], $message['expires'], $message['role']));
            } catch (Throwable $e) {
                Log::error('identity.invitation.delivery_failed', ['invitation_id' => $message['id'], 'exception' => $e::class]);
                $failed ??= $message['id'];
            }
        }

        if ($failed !== null) {
            throw new InvitationDeliveryFailed($failed);
        }
    }
}
