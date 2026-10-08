<?php

use App\Modules\Connector\Infrastructure\ConnectorAuditSerializer;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditField;
use App\Platform\Audit\AuditHasher;
use App\Platform\Audit\AuditSerializer;
use App\Platform\Audit\AuditSerializers;

function serializers(): AuditSerializers
{
    $registry = new AuditSerializers(new AuditHasher('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='));
    $registry->register(new class implements AuditSerializer
    {
        public function module(): string
        {
            return 'access';
        }

        public function fields(): array
        {
            return ['user_id' => AuditField::Id, 'role' => AuditField::Enum, 'note' => AuditField::Hashed];
        }
    });

    return $registry;
}

it('drops unlisted fields and stores free text as a keyed hash, never raw', function () {
    $stored = serializers()->serialize(AuditAction::AccessRoleChanged, [
        'user_id' => 7, 'role' => 'admin', 'note' => 'a private note', 'token' => 'secret',
    ]);

    expect(array_keys($stored))->toBe(['user_id', 'role', 'note'])
        ->and($stored['user_id'])->toBe(7)
        ->and($stored['role'])->toBe('admin')
        ->and($stored['note'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and(json_encode($stored))->not->toContain('private note')->not->toContain('secret');
});

it('hashes deterministically with a key derived from APP_KEY, and differently for another key', function () {
    $hash = fn (string $key, string $v) => (new AuditHasher($key))->hash($v);

    expect($hash('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'x'))->toBe($hash('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'x'))
        ->and($hash('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'x'))->not->toBe($hash('base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=', 'x'))
        ->and($hash('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', '1'))->not->toBe((new AuditHasher('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='))->hash(1));
});

it('refuses an ID or enum field holding free text, and a module without a serializer', function () {
    expect(fn () => serializers()->serialize(AuditAction::AccessRoleChanged, ['user_id' => 'Alice']))->toThrow(InvalidArgumentException::class);
    expect(fn () => serializers()->serialize(AuditAction::AccessRoleChanged, ['role' => 'Some Free Text']))->toThrow(InvalidArgumentException::class);
    expect(fn () => serializers()->serialize(AuditAction::IdentitySigninFailed, []))->toThrow(InvalidArgumentException::class);
});

it('stores a host field as is when it is a lower-case host name or IP literal, and refuses anything else', function () {
    $registry = new AuditSerializers(new AuditHasher('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='));
    $registry->register(new class implements AuditSerializer
    {
        public function module(): string
        {
            return 'connector';
        }

        public function fields(): array
        {
            return ['host' => AuditField::Host];
        }
    });

    expect($registry->serialize(AuditAction::ConnectorHostAllowlistEntryCreated, ['host' => 'api.example.com']))->toBe(['host' => 'api.example.com'])
        ->and($registry->serialize(AuditAction::ConnectorHostAllowlistEntryCreated, ['host' => '[2606:4700::1111]']))->toBe(['host' => '[2606:4700::1111]'])
        ->and($registry->serialize(AuditAction::ConnectorHostAllowlistEntryCreated, ['host' => null]))->toBe(['host' => null])
        ->and(fn () => $registry->serialize(AuditAction::ConnectorHostAllowlistEntryCreated, ['host' => 'ada@example.test']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->serialize(AuditAction::ConnectorHostAllowlistEntryCreated, ['host' => 'Mixed.Case']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->serialize(AuditAction::ConnectorHostAllowlistEntryCreated, ['host' => 'a.example/path']))->toThrow(InvalidArgumentException::class);
});

// Story 2.9: an Endpoint's audit state is ids, the method, counts and keyed hashes; never the path or a binding in the clear.
it('serializes an Endpoint with ids, the method and counts in the clear and the path and bindings as keyed hashes', function () {
    $registry = new AuditSerializers(new AuditHasher('base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='));
    $registry->register(new ConnectorAuditSerializer);
    $id = '01a11a57-c520-70b8-a220-3c7a161cf176';

    $stored = $registry->serialize(AuditAction::ConnectorEndpointRevised, [
        'endpoint_id' => $id, 'data_source_id' => $id, 'method' => 'post', 'revision' => 2, 'param_count' => 3, 'header_count' => 1,
        'read_only_query' => 'true', 'path' => '/customers/secret', 'bindings' => '[["id","fixed","c-42"]]', 'unlisted' => 'dropped',
    ]);

    expect($stored)->toMatchArray(['endpoint_id' => $id, 'method' => 'post', 'revision' => 2, 'param_count' => 3, 'header_count' => 1, 'read_only_query' => 'true'])
        ->and($stored['path'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and($stored['bindings'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and($stored)->not->toHaveKey('unlisted')
        ->and(json_encode($stored))->not->toContain('secret')->not->toContain('c-42')
        ->and(fn () => $registry->serialize(AuditAction::ConnectorEndpointCreated, ['method' => 'POST']))->toThrow(InvalidArgumentException::class);
});
