<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\AttributeValueInvalid;
use App\Modules\Access\Contracts\AttributeValueRow;
use App\Modules\Access\Contracts\AttributeVault;
use App\Modules\Access\Contracts\MemberAttributes;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\SelfChangeForbidden;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sets and reads a member's user attribute values (Story 2.12) inside the Workspace's transaction.
 *
 * The order of refusals is: the editor's own membership (nobody edits their own attributes), a membership that row-level
 * security does not show (404), a value for a key that is not defined or that is empty, too long or the wrong shape
 * (422, nothing stored), and last an unusable `data` or `digest` key (503, nothing stored). A value is sealed and indexed
 * by {@see AttributeVault}; an identical value (the same blind index) changes nothing and records nothing. Each changed
 * key writes `access.attribute.changed` to the audit log (the member and key IDs and a keyed hash of the value, never the
 * value) and to the outbox (IDs only) in the same transaction. Concurrent writes to one member's values are
 * last-write-wins, each audited. The plain value is only ever returned to the caller of {@see self::forMember()}.
 */
final class ManageMemberAttributes implements MemberAttributes
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly AttributeVault $vault,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    public function forMember(string $workspaceId, string $membershipId): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $membershipId): array {
            $membershipId = $this->member($workspaceId, $membershipId);

            /** @var list<object{id: string, key_id: string, label: string, value_type: string}> $keys */
            $keys = DB::select('select id, key_id, label, value_type from user_attribute_keys where workspace_id = ? order by key_id', [$workspaceId]);

            if ($keys === []) {
                return [];
            }

            $this->vault->assertAvailable();

            /** @var list<object{attribute_key_id: string, sealed: string}> $stored */
            $stored = DB::select(
                "select attribute_key_id, encode(value_ciphertext, 'base64') as sealed from user_attributes where workspace_id = ? and membership_id = ?",
                [$workspaceId, $membershipId],
            );
            $sealedBy = [];

            foreach ($stored as $row) {
                $sealedBy[strtolower($row->attribute_key_id)] = $row->sealed;
            }

            $rows = [];

            foreach ($keys as $key) {
                $sealed = $sealedBy[strtolower($key->id)] ?? null;
                $raw = $sealed === null ? false : base64_decode($sealed, true);
                $value = $raw === false ? null : $this->vault->open($workspaceId, $membershipId, $key->key_id, $raw);

                $rows[] = new AttributeValueRow($key->key_id, $key->label, $key->value_type, $value);
            }

            return $rows;
        });
    }

    public function set(MemberEditor $editor, string $membershipId, array $values): array
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $membershipId, $values): array {
            if (strtolower($membershipId) === strtolower($editor->membershipId)) {
                throw new SelfChangeForbidden;
            }

            $membershipId = $this->member($editor->workspaceId, $membershipId);

            /** @var list<object{id: string, key_id: string, value_type: string}> $keys */
            $keys = DB::select('select id, key_id, value_type from user_attribute_keys where workspace_id = ? order by key_id', [$editor->workspaceId]);
            $defined = [];

            foreach ($keys as $key) {
                $defined[$key->key_id] = $key;
            }

            $clean = [];
            $reasons = [];

            foreach ($values as $keyId => $raw) {
                $keyId = (string) $keyId;

                if (! isset($defined[$keyId])) {
                    $reasons[$keyId] = 'undefined_key';

                    continue;
                }

                try {
                    $clean[$keyId] = AttributeValueRules::normalise($defined[$keyId]->value_type, $raw);
                } catch (InvalidArgumentException $e) {
                    $reasons[$keyId] = $e->getMessage();
                }
            }

            if ($reasons !== []) {
                throw new AttributeValueInvalid($reasons);
            }

            if ($clean === []) {
                return [];
            }

            $this->vault->assertAvailable();
            ksort($clean);

            $changed = [];

            foreach ($clean as $keyId => $value) {
                $key = $defined[$keyId];
                $index = $this->vault->blindIndex($editor->workspaceId, $keyId, $value);

                /** @var object{value_blind_index: string, blind_index_version: int|string}|null $existing */
                $existing = DB::selectOne(
                    'select value_blind_index, blind_index_version from user_attributes where membership_id = ? and attribute_key_id = ? for update',
                    [$membershipId, $key->id],
                );

                if ($existing !== null
                    && hash_equals($existing->value_blind_index, $index)
                    && (int) $existing->blind_index_version === AttributeVault::BLIND_INDEX_VERSION) {
                    continue;
                }

                $sealed = base64_encode($this->vault->seal($editor->workspaceId, $membershipId, $keyId, $value));

                DB::insert(
                    "insert into user_attributes (id, workspace_id, membership_id, attribute_key_id, value_ciphertext, value_blind_index, blind_index_version, updated_at) values (?, ?, ?, ?, decode(?, 'base64'), ?, ?, ?)
                     on conflict (membership_id, attribute_key_id) do update set value_ciphertext = excluded.value_ciphertext, value_blind_index = excluded.value_blind_index, blind_index_version = excluded.blind_index_version, updated_at = excluded.updated_at",
                    [(string) Str::uuid7(), $editor->workspaceId, $membershipId, $key->id, $sealed, $index, AttributeVault::BLIND_INDEX_VERSION, now()],
                );

                $subject = 'membership:'.$membershipId;

                $this->audit->record(
                    AuditAction::AccessAttributeChanged,
                    ['membership_id' => $membershipId, 'attribute_key_id' => $key->id, 'attribute_key' => $keyId, 'attribute_value' => $value],
                    subject: $subject,
                    actor: $editor->membershipId,
                );
                $this->outbox->emit(
                    AuditAction::AccessAttributeChanged,
                    $subject,
                    ['membership_id' => $membershipId, 'attribute_key_id' => $key->id, 'attribute_key' => $keyId],
                    actor: $editor->membershipId,
                );

                $changed[] = $keyId;
            }

            return $changed;
        });
    }

    /** The membership's ID (lower case) when it is a member of this Workspace (row-level security shows no other). */
    private function member(string $workspaceId, string $membershipId): string
    {
        if (! Str::isUuid($membershipId)) {
            throw new MembershipNotFound;
        }

        $membershipId = strtolower($membershipId);
        $row = DB::selectOne('select id from workspace_memberships where id = ? and workspace_id = ?', [$membershipId, $workspaceId]);

        return $row === null ? throw new MembershipNotFound : $membershipId;
    }
}
