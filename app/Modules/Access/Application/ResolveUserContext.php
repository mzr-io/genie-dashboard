<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\AttributeVault;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\UserContext;
use App\Modules\Access\Contracts\UserContextValues;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves user-context bindings from server-side membership data (Story 2.13) inside the Workspace's transaction. The membership
 * must be an active one of the Workspace (404 semantics otherwise). The ID is the membership ID, the email is the account's (the
 * Identity `users` row through the Access member view), the group is the name of the member's one group (none or several: missing,
 * named `user_group`) and an attribute is opened from the vault (an unset value: missing, named by its key id; an undefined key:
 * missing too). Nothing is logged or audited here, and no value is ever part of an exception message.
 */
final class ResolveUserContext implements UserContext
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly AttributeVault $vault,
    ) {}

    public function resolve(string $workspaceId, string $membershipId, array $bindings): UserContextValues
    {
        if (! Str::isUuid($membershipId)) {
            throw new MembershipNotFound;
        }

        $membershipId = strtolower($membershipId);

        return $this->transactions->run($workspaceId, function () use ($workspaceId, $membershipId, $bindings): UserContextValues {
            /** @var object{id: string, email: string, status: string}|null $member */
            $member = DB::selectOne('select id, email, status from access_workspace_members where workspace_id = ? and id = ?', [$workspaceId, $membershipId]);

            if ($member === null || $member->status !== 'active') {
                throw new MembershipNotFound;
            }

            $values = [];
            $missing = [];
            $groups = null;
            $attributes = null;
            // Only the bound keys are selected and opened: an unrelated value is never decrypted.
            $wanted = array_values(array_unique(array_filter(array_map(
                fn (array $b): ?string => $b['binding'] === self::USER_ATTRIBUTE ? ($b['key'] ?? null) : null,
                $bindings,
            ), fn (?string $key): bool => is_string($key) && $key !== '')));

            foreach ($bindings as $ref => $binding) {
                $kind = $binding['binding'];
                $key = $binding['key'] ?? null;

                if ($kind === self::USER_ID) {
                    $values[$ref] = strtolower($member->id);
                } elseif ($kind === self::USER_EMAIL) {
                    $values[$ref] = $member->email;
                } elseif ($kind === self::USER_GROUP) {
                    /** @var list<object{name: string}> $found */
                    $found = $groups === null ? DB::select('select g.name from group_members gm join user_groups g on g.workspace_id = gm.workspace_id and g.id = gm.group_id where gm.workspace_id = ? and gm.membership_id = ? order by g.name', [$workspaceId, $membershipId]) : [];
                    $groups ??= array_map(fn (object $row): string => $row->name, $found);

                    // Zero or several groups: there is no one group to send, so nothing is sent.
                    count($groups) === 1 ? $values[$ref] = $groups[0] : $missing[] = self::USER_GROUP;
                } elseif ($kind === self::USER_ATTRIBUTE && is_string($key) && $key !== '') {
                    $attributes ??= $this->attributes($workspaceId, $membershipId, $wanted);

                    isset($attributes[$key]) ? $values[$ref] = $attributes[$key] : $missing[] = $key;
                } else {
                    $missing[] = $kind;
                }
            }

            $missing = array_values(array_unique($missing));
            sort($missing);

            return new UserContextValues($values, $missing);
        });
    }

    /**
     * The member's set attribute values by key id.
     *
     * @param  list<string>  $keyIds
     * @return array<string, string>
     */
    private function attributes(string $workspaceId, string $membershipId, array $keyIds): array
    {
        /** @var list<object{key_id: string, sealed: string}> $rows */
        $rows = DB::select(
            "select k.key_id, encode(a.value_ciphertext, 'base64') as sealed from user_attributes a join user_attribute_keys k on k.workspace_id = a.workspace_id and k.id = a.attribute_key_id where a.workspace_id = ? and a.membership_id = ? and k.key_id = any(?::text[])",
            [$workspaceId, $membershipId, '{'.implode(',', array_map(fn (string $k): string => '"'.addcslashes($k, '"\\').'"', $keyIds)).'}'],
        );

        if ($rows !== []) {
            $this->vault->assertReadable();
        }

        $values = [];

        foreach ($rows as $row) {
            $raw = base64_decode($row->sealed, true);

            if ($raw !== false) {
                $values[$row->key_id] = $this->vault->open($workspaceId, $membershipId, $row->key_id, $raw);
            }
        }

        return $values;
    }
}
