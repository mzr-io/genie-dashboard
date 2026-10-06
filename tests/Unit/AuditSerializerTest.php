<?php

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
