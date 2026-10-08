<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\AttributeKeyNotFound;
use App\Modules\Access\Contracts\AttributeKeyRevisionConflict;
use App\Modules\Access\Contracts\AttributeKeyRow;
use App\Modules\Access\Contracts\AttributeKeys;
use App\Modules\Access\Contracts\AttributeKeyTaken;
use App\Modules\Access\Contracts\MemberEditor;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The catalogue of user attribute keys (Story 2.12) inside the Workspace's transaction. A key id and a value type are
 * fixed at creation (a trigger refuses any UPDATE of them); the label changes under the key's `revision`. Both changes
 * write their audit event (`access.attribute_key.created`, `access.attribute_key.renamed`) in the same transaction:
 * the key id and the value type, or the key id and a keyed hash of the label. There is no delete, and no cap.
 */
final class ManageAttributeKeys implements AttributeKeys
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    private const COLUMNS = 'id, key_id, label, value_type, revision, to_char(created_at, '.self::STAMP.') as created, to_char(updated_at, '.self::STAMP.') as updated';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
    ) {}

    public function list(string $workspaceId, ?string $search = null): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $search): array {
            $needle = $search === null ? '' : trim($search);

            if ($needle === '') {
                $rows = DB::select('select '.self::COLUMNS.' from user_attribute_keys where workspace_id = ? order by key_id', [$workspaceId]);
            } else {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($needle)).'%';
                $rows = DB::select(
                    'select '.self::COLUMNS." from user_attribute_keys where workspace_id = ? and (lower(key_id) like ? escape '!' or lower(label) like ? escape '!') order by key_id",
                    [$workspaceId, $pattern, $pattern],
                );
            }

            return array_values(array_map($this->row(...), $rows));
        });
    }

    public function count(string $workspaceId): int
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId): int {
            /** @var object{n: int|string} $row */
            $row = DB::selectOne('select count(*) as n from user_attribute_keys where workspace_id = ?', [$workspaceId]);

            return (int) $row->n;
        });
    }

    public function create(MemberEditor $editor, string $keyId, string $label, string $valueType): AttributeKeyRow
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,47}\z/D', $keyId) !== 1 || ! in_array($valueType, AttributeKeyRow::TYPES, true)) {
            throw new InvalidArgumentException('The key id or the value type is not valid.');
        }

        return $this->transactions->run($editor->workspaceId, function () use ($editor, $keyId, $label, $valueType): AttributeKeyRow {
            if (DB::selectOne('select 1 as taken from user_attribute_keys where workspace_id = ? and key_id = ?', [$editor->workspaceId, $keyId]) !== null) {
                throw new AttributeKeyTaken('key_id');
            }

            $this->assertLabelFree($editor->workspaceId, $label, null);

            $id = (string) Str::uuid7();

            try {
                DB::insert(
                    'insert into user_attribute_keys (id, workspace_id, key_id, label, value_type, revision, created_at, updated_at) values (?, ?, ?, ?, ?, 1, ?, ?)',
                    [$id, $editor->workspaceId, $keyId, $label, $valueType, now(), now()],
                );
            } catch (QueryException $e) {
                throw $this->taken($e);
            }

            $this->audit->record(
                AuditAction::AccessAttributeKeyCreated,
                ['attribute_key_id' => $id, 'attribute_key' => $keyId, 'value_type' => $valueType],
                subject: 'attribute_key:'.$id,
                actor: $editor->membershipId,
            );

            return $this->find($id);
        });
    }

    public function rename(MemberEditor $editor, string $keyId, string $label, int $revision): AttributeKeyRow
    {
        return $this->transactions->run($editor->workspaceId, function () use ($editor, $keyId, $label, $revision): AttributeKeyRow {
            $row = DB::selectOne('select id from user_attribute_keys where workspace_id = ? and key_id = ? for update', [$editor->workspaceId, $keyId]);

            if ($row === null) {
                throw new AttributeKeyNotFound;
            }

            /** @var object{id: string} $row */
            $current = $this->find($row->id);

            if ($current->revision !== $revision) {
                throw new AttributeKeyRevisionConflict($current);
            }

            if ($current->label === $label) {
                return $current;
            }

            $this->assertLabelFree($editor->workspaceId, $label, $current->id);

            try {
                DB::update(
                    'update user_attribute_keys set label = ?, revision = revision + 1, updated_at = ? where id = ? and workspace_id = ?',
                    [$label, now(), $current->id, $editor->workspaceId],
                );
            } catch (QueryException $e) {
                throw $this->taken($e);
            }

            $this->audit->record(
                AuditAction::AccessAttributeKeyRenamed,
                ['attribute_key_id' => $current->id, 'attribute_key' => $current->keyId, 'label' => $label],
                ['attribute_key_id' => $current->id, 'label' => $current->label],
                subject: 'attribute_key:'.$current->id,
                actor: $editor->membershipId,
            );

            return $this->find($current->id);
        });
    }

    private function find(string $id): AttributeKeyRow
    {
        $row = DB::selectOne('select '.self::COLUMNS.' from user_attribute_keys where id = ?', [$id]);

        return $row === null ? throw new AttributeKeyNotFound : $this->row($row);
    }

    private function row(object $row): AttributeKeyRow
    {
        /** @var object{id: string, key_id: string, label: string, value_type: string, revision: int|string, created: string, updated: string} $row */
        return new AttributeKeyRow($row->id, $row->key_id, $row->label, $row->value_type, (int) $row->revision, $row->created, $row->updated);
    }

    private function assertLabelFree(string $workspaceId, string $label, ?string $exceptId): void
    {
        $taken = DB::selectOne(
            'select 1 as taken from user_attribute_keys where workspace_id = ? and lower(btrim(label)) = lower(btrim(?)) and (?::uuid is null or id <> ?::uuid) limit 1',
            [$workspaceId, $label, $exceptId, $exceptId],
        );

        if ($taken !== null) {
            throw new AttributeKeyTaken('label');
        }
    }

    private function taken(QueryException $e): QueryException|AttributeKeyTaken
    {
        // A concurrent create or rename won the race: the unique index refuses the second.
        if (($e->errorInfo[0] ?? null) !== '23505') {
            return $e;
        }

        return new AttributeKeyTaken(str_contains((string) ($e->errorInfo[2] ?? ''), 'label') ? 'label' : 'key_id');
    }
}
