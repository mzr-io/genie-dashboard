<?php

use App\Modules\Blocks\Infrastructure\BlocksAuditSerializer;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditHasher;
use App\Platform\Audit\AuditSerializers;

// Story 3.3: Blocks' audit allowlist. IDs, the Block Type key and the revision in the clear; the name only as a keyed hash;
// everything else (a config, a sample, a secret) is dropped.
function blocksSerializers(): AuditSerializers
{
    $registry = new AuditSerializers(new AuditHasher('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='));
    $registry->register(new BlocksAuditSerializer);

    return $registry;
}

it('names the draft actions in the blocks module', function () {
    expect(AuditAction::BlocksDraftCreated->value)->toBe('blocks.draft.created')
        ->and(AuditAction::BlocksDraftDiscarded->value)->toBe('blocks.draft.discarded')
        ->and(AuditAction::BlocksDraftCreated->module())->toBe('blocks')
        ->and(AuditAction::fromString('blocks.draft.discarded'))->toBe(AuditAction::BlocksDraftDiscarded);
});

it('keeps IDs, the type and the revision, hashes the name and drops every other field', function () {
    $id = '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000';
    $stored = blocksSerializers()->serialize(AuditAction::BlocksDraftCreated, [
        'block_id' => $id, 'draft_id' => $id, 'block_type' => 'kpi-card', 'name' => 'Revenue today', 'revision' => 1,
        'config' => ['query_plan' => []], 'sample' => '{"a":1}', 'api_key' => 'sk-1',
    ]);

    expect(array_keys($stored))->toBe(['block_id', 'draft_id', 'block_type', 'name', 'revision'])
        ->and($stored['block_id'])->toBe($id)
        ->and($stored['block_type'])->toBe('kpi-card')
        ->and($stored['revision'])->toBe(1)
        ->and($stored['name'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and(json_encode($stored))->not->toContain('Revenue')->not->toContain('sk-1')->not->toContain('query_plan');
});

it('refuses an ID field holding free text', function () {
    expect(fn () => blocksSerializers()->serialize(AuditAction::BlocksDraftCreated, ['draft_id' => 'Revenue today']))->toThrow(InvalidArgumentException::class);
});
