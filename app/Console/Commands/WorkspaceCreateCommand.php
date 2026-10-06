<?php

namespace App\Console\Commands;

use App\Modules\Identity\Contracts\InvitationIssuer;
use App\Modules\Identity\Contracts\InvitationLifetimeUnset;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditHasher;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way to create a Workspace (FR-3): there is no screen, route or API for it.
 *
 * The Workspace, the hashed invitation and the `operator_audit` row are written in one transaction on the
 * `operator` connection (role `operator`, INSERT-only grants), and the invitation is emailed before it
 * commits, so a failed email leaves nothing behind. The action is then mirrored into the Workspace audit
 * log as role `app`, which cannot see the new Workspace until the operator transaction has committed.
 */
#[Signature('dashflow:workspace:create {name : Workspace name} {label : Short label} {admin-email : Email of the first Admin}')]
class WorkspaceCreateCommand extends Command
{
    public const CONNECTION = 'operator';

    protected $description = 'Create a Workspace and email its first Admin a single-use invitation (operator only)';

    /** `operator:<OS user running the command>`, as a slug; `operator:unknown` when it cannot be told. */
    public static function actor(): string
    {
        $name = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        $slug = substr(trim((string) preg_replace('/[^a-z0-9_.-]+/', '_', strtolower((string) $name)), '_'), 0, 40);

        return 'operator:'.($slug === '' ? 'unknown' : $slug);
    }

    public function handle(InvitationIssuer $invitations, WorkspaceTransaction $transactions, Audit $audit, AuditHasher $hasher): int
    {
        try {
            $invitations->lifetimeHours();
        } catch (InvitationLifetimeUnset $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $input = [
            'name' => trim((string) $this->argument('name')),
            'label' => trim((string) $this->argument('label')),
            'email' => trim((string) $this->argument('admin-email')),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'regex:/\A[^\p{C}]+\z/u'],
            'label' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ], [
            'name.regex' => 'The name must not contain control characters or line breaks.',
            'label.regex' => 'The label must be a lower-case slug: letters, digits and single hyphens, like acme-corp.',
        ], ['email' => 'admin-email']);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::INVALID;
        }

        $workspaceId = (string) Str::uuid7();
        $connection = DB::connection(self::CONNECTION);
        $actor = self::actor();

        try {
            // Column-limited: `operator` may read `label` only, so no `select *`.
            $taken = $connection->table('workspaces')->where('label', $input['label'])->value('label') !== null;
        } catch (Throwable $e) {
            $this->components->error('Nothing was created: '.$e::class.'.');

            return self::FAILURE;
        }

        if ($taken) {
            $this->components->error("A Workspace with the label \"{$input['label']}\" already exists. Choose another label.");

            return self::INVALID;
        }

        try {
            $invitationId = $connection->transaction(function () use ($connection, $invitations, $hasher, $workspaceId, $input, $actor): string {
                $connection->table('workspaces')->insert([
                    'id' => $workspaceId,
                    'name' => $input['name'],
                    'label' => $input['label'],
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $invitationId = $invitations->issue($connection, $workspaceId, $input['name'], $input['email'], $actor);

                $connection->table('operator_audit')->insert([
                    'id' => (string) Str::uuid7(),
                    'action' => AuditAction::PlatformWorkspaceCreated->value,
                    'actor' => $actor,
                    'workspace_id' => $workspaceId,
                    'details' => json_encode(['invitation_id' => $invitationId, 'role' => 'admin', 'email' => $hasher->hash(strtolower($input['email']))], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                ]);

                return $invitationId;
            });
        } catch (Throwable $e) {
            // Class only: a message could carry the address or a driver detail.
            $this->components->error('Nothing was created: '.$e::class.'.');

            return self::FAILURE;
        }

        try {
            $transactions->run($workspaceId, fn () => $audit->record(
                AuditAction::PlatformWorkspaceCreated,
                ['workspace_id' => $workspaceId, 'invitation_id' => $invitationId, 'role' => 'admin', 'email' => strtolower($input['email'])],
                subject: 'workspace:'.$workspaceId,
                actor: $actor,
            ));
        } catch (Throwable $e) {
            $this->components->error("Workspace {$workspaceId} was created and the invitation sent, but mirroring it into the Workspace audit log failed: ".$e::class.'.');

            return self::FAILURE;
        }

        $this->components->info("Workspace {$workspaceId} created. An invitation was emailed to the first Admin.");

        return self::SUCCESS;
    }
}
