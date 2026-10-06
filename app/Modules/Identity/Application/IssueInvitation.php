<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\InvitationIssuer;
use App\Modules\Identity\Contracts\InvitationLifetimeUnset;
use App\Modules\Identity\Infrastructure\InvitationMail;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates the invitation row (hash only) and emails the link. The plain token lives in this method
 * and in the email; it is neither stored, logged nor returned.
 */
final class IssueInvitation implements InvitationIssuer
{
    public function __construct(private readonly Repository $config) {}

    public function lifetimeHours(): int
    {
        $value = $this->config->get('dashflow.tunables.users.invitation_lifetime.value');

        if (! is_scalar($value) || ! ctype_digit((string) $value) || strlen((string) $value) > 9 || (int) $value < 1) {
            throw new InvitationLifetimeUnset;
        }

        if ((int) $value > InvitationLifetimeUnset::MAX_HOURS) {
            throw new InvitationLifetimeUnset('The invitation lifetime is too long. DASHFLOW_INVITATION_LIFETIME is at most '.InvitationLifetimeUnset::MAX_HOURS.' hours.');
        }

        return (int) $value;
    }

    public function issue(ConnectionInterface $connection, string $workspaceId, string $workspaceName, string $email, string $createdBy): string
    {
        $hours = $this->lifetimeHours();
        $token = InvitationToken::generate();
        $id = (string) Str::uuid7();
        $expiresAt = now()->addHours($hours);

        $connection->table('invitations')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'email' => strtolower($email),
            'token_hash' => InvitationToken::hash($token),
            'role' => 'admin',
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::to($email)->send(new InvitationMail(url('/invitations/'.$token), $workspaceName, $expiresAt));

        return $id;
    }
}
