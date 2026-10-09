<?php

use App\Modules\Ingestion\Application\Jcs;
use App\Modules\Ingestion\Application\ResolveFetchKey;
use App\Modules\Ingestion\Contracts\ContextDigest;
use App\Modules\Ingestion\Contracts\FetchKeyInput;
use App\Modules\Ingestion\Contracts\FetchKeyResult;
use App\Modules\Ingestion\Infrastructure\KeyFileContextDigest;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use Illuminate\Config\Repository;

// Story 2.14: the golden vectors of the fetch key. Every expected value below was computed outside the application, by an independent
// RFC 8785 implementation (Python: sorted by UTF-16 code units, ES string escaping) and `hashlib` / `hmac`, so a change that alters
// a key (a reordered field, a different escape, a float, a new field) fails CI here instead of silently orphaning every stored payload.

const FK_WORKSPACE = '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b';
const FK_REVISION = '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2c';

function fkKey(): string
{
    return str_repeat("\x01", 32);
}

function fkResolver(?string $key = null): ResolveFetchKey
{
    $key ??= fkKey();

    return new ResolveFetchKey(new class($key) implements ContextDigest
    {
        public function __construct(private readonly string $key) {}

        public function key(string $workspaceId): ?string
        {
            return $this->key === '' ? null : $this->key;
        }
    });
}

/** @param  array<string, array{t: string, v: string}|null>  $params */
function fkInput(array $params, ?array $bound = null, bool $scope = false, ?string $member = null, int $dataSource = 3): FetchKeyInput
{
    return new FetchKeyInput(FK_WORKSPACE, FK_REVISION, $dataSource, $params, $bound, $scope, $member);
}

it('matches the golden vectors for shared data', function (array $params, string $expected) {
    $result = fkResolver()->resolve(fkInput($params));

    expect($result->ok())->toBeTrue()->and($result->key)->toBe($expected);
})->with([
    'one date' => [['from' => ['t' => 'date', 'v' => '2026-10-01']], 'fk1:0d4758ebbfd5df082c1d66f76d66fbc75c1dbe491a6679bbd656bb9076fb5269'],
    'no parameters is an empty object' => [[], 'fk1:52aed88a3034b346f748a0c6e721a3217c743a487773cad1d4a207a76cd67800'],
    'every type, keys in any order, escapes and non-ASCII' => [[
        'name' => ['t' => 'string', 'v' => "a\"b\\c\n é €"],
        'n' => ['t' => 'number', 'v' => '1.10'],
        'b' => ['t' => 'bool', 'v' => 'true'],
        'd' => ['t' => 'date', 'v' => '2026-02-28'],
        'at' => ['t' => 'datetime', 'v' => '2026-10-08T12:30:00Z'],
    ], 'fk1:d845f5d6086983c2d13dbb8fcb3ae832d66e2622a739f42e23e7f388b97d9ca3'],
    'names sort by UTF-16 code units, not UTF-8 bytes' => [[
        "\u{FB33}" => ['t' => 'string', 'v' => 'x'],
        "\u{1F600}" => ['t' => 'string', 'v' => 'y'],
    ], 'fk1:5157e9d48e14c46bd97f02359390e4175106d48a1691a701b00b06ed4a565d05'],
    'a numeric parameter name is still an object member' => [['0' => ['t' => 'string', 'v' => 'zero']], 'fk1:694a48d96f1e3a8c877a75ae182e1f7cdfa2c679b5e802f44a998ee032d7feac'],
    'a header is named header:{name}' => [['header:X-Region' => ['t' => 'string', 'v' => 'eu']], 'fk1:c59f232eb648872eed42d2f5c4f80af8e33589daeff2b0858a3098f56088938e'],
]);

it('does not depend on the order of the parameters, and leaves an absent binding out', function () {
    $a = fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01'], 'to' => ['t' => 'date', 'v' => '2026-10-31']]));
    $b = fkResolver()->resolve(fkInput(['to' => ['t' => 'date', 'v' => '2026-10-31'], 'from' => ['t' => 'date', 'v' => '2026-10-01']]));
    $absent = fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01'], 'gone' => null]));
    $without = fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01']]));

    expect($a->key)->toBe($b->key)->and($absent->key)->toBe($without->key)
        ->and($without->key)->toBe('fk1:0d4758ebbfd5df082c1d66f76d66fbc75c1dbe491a6679bbd656bb9076fb5269');
});

it('changes with the Data Source revision, the Endpoint revision, the Workspace and any value', function () {
    $base = fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01']]))->key;

    expect(fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01']], dataSource: 4))->key)->not->toBe($base)
        ->and(fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-02']]))->key)->not->toBe($base)
        ->and(fkResolver()->resolve(fkInput(['from' => ['t' => 'string', 'v' => '2026-10-01']]))->key)->not->toBe($base)
        ->and(fkResolver()->resolve(new FetchKeyInput('0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2d', FK_REVISION, 3, ['from' => ['t' => 'date', 'v' => '2026-10-01']]))->key)->not->toBe($base)
        ->and(fkResolver()->resolve(new FetchKeyInput(FK_WORKSPACE, '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2e', 3, ['from' => ['t' => 'date', 'v' => '2026-10-01']]))->key)->not->toBe($base);
});

it('matches the golden vectors for user-bound data', function () {
    $attrs = ['region' => 'emea', 'header:X-Tenant' => 'acme'];
    $params = ['from' => ['t' => 'date', 'v' => '2026-10-01']];

    // The attribute map is hashed in key order whatever the order it arrives in.
    expect(fkResolver()->resolve(fkInput($params, $attrs))->key)->toBe('fk1:781274de2bcbc299e3889aad4f958f0764c57266c324d1f338d715c5f17a03ff')
        ->and(fkResolver()->resolve(fkInput($params, array_reverse($attrs)))->key)->toBe('fk1:781274de2bcbc299e3889aad4f958f0764c57266c324d1f338d715c5f17a03ff')
        ->and(fkResolver()->resolve(fkInput($params))->key)->not->toBe('fk1:781274de2bcbc299e3889aad4f958f0764c57266c324d1f338d715c5f17a03ff');
});

it('gives two members different keys when the Endpoint is scoped by the caller, and the same key when it is not', function () {
    $attrs = ['region' => 'emea', 'header:X-Tenant' => 'acme'];
    $params = ['from' => ['t' => 'date', 'v' => '2026-10-01']];
    $one = '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a01';
    $two = '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a02';

    $scopedOne = fkResolver()->resolve(fkInput($params, $attrs, true, $one))->key;
    $scopedTwo = fkResolver()->resolve(fkInput($params, $attrs, true, $two))->key;
    $shared = fkResolver()->resolve(fkInput($params, $attrs))->key;

    expect($scopedOne)->not->toBe($scopedTwo)->and($scopedOne)->not->toBe($shared)
        // The same attributes, not scoped: one fetch for everyone who has them, whoever asks.
        ->and(fkResolver()->resolve(fkInput($params, $attrs, false, $one))->key)->toBe($shared)
        ->and(fkResolver()->resolve(fkInput($params, $attrs, false, $two))->key)->toBe($shared);
});

it('keeps the context digest vectors', function () {
    $attrs = ['region' => 'emea', 'header:X-Tenant' => 'acme'];
    $contextOf = fn (array $map): string => hash_hmac('sha256', 'bound|'.Jcs::encode((object) $map), fkKey());

    expect($contextOf($attrs))->toBe('3fcf9176c93ddb4ecebef5d55795f94c46dcb94267cd587a35981ad51e7b128d')
        ->and($contextOf([...$attrs, 'membership_id' => '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a01']))->toBe('7c553b84042a79e328a8884c217bc60085d32eb6a8ffe30fc1efcedfa0655552')
        ->and($contextOf([...$attrs, 'membership_id' => '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a02']))->toBe('45acea2a3095cf6ff45cc311f3bb8ad7f53552988fdca7f0b9a9c62e5e64535e');
});

it('produces no key and access.context_missing for a missing, null, empty or non-string bound value', function (mixed $value) {
    $result = fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01']], ['region' => 'emea', 'tenant' => $value]));

    expect($result->ok())->toBeFalse()->and($result->key)->toBeNull()->and($result->reason)->toBe('access.context_missing')
        ->and(FetchKeyResult::CONTEXT_MISSING)->toBe('access.context_missing');
})->with(['null' => [null], 'empty' => [''], 'an integer' => [5], 'a list' => [['x']], 'a bool' => [true]]);

it('produces no key when a bound attribute is not valid UTF-8, when the digest key is unusable and when a scoped member is unknown', function () {
    $params = ['from' => ['t' => 'date', 'v' => '2026-10-01']];

    expect(fkResolver()->resolve(fkInput($params, ['region' => "\xff"]))->reason)->toBe('access.context_missing')
        ->and(fkResolver('')->resolve(fkInput($params, ['region' => 'emea']))->reason)->toBe('access.context_missing')
        ->and(fkResolver()->resolve(fkInput($params, ['region' => 'emea'], true, null))->reason)->toBe('access.context_missing')
        ->and(fkResolver()->resolve(fkInput($params, ['region' => 'emea'], true, 'not-a-uuid'))->reason)->toBe('access.context_missing');

    // Shared data needs no digest key at all.
    expect(fkResolver('')->resolve(fkInput($params))->ok())->toBeTrue();
});

it('never holds a bound value in the result or its dump', function () {
    $result = fkResolver()->resolve(fkInput(['from' => ['t' => 'date', 'v' => '2026-10-01']], ['region' => 'CANARY-VALUE']));
    $missing = fkResolver()->resolve(fkInput([], ['region' => 'CANARY-VALUE', 'other' => '']));

    expect(var_export($result, true).var_export($missing, true).print_r(fkInput([], ['region' => 'CANARY-VALUE']), true))->not->toContain('CANARY-VALUE');
});

it('refuses a malformed parameter, which is a programming error and never a key', function (array $param) {
    expect(fn () => fkResolver()->resolve(fkInput(['p' => $param])))->toThrow(InvalidArgumentException::class);
})->with([
    'an unknown type' => [['t' => 'float', 'v' => '1.5']],
    'a number that is not a lexeme' => [['t' => 'number', 'v' => '1,5']],
    'a bool written otherwise' => [['t' => 'bool', 'v' => 'yes']],
    'a date that does not exist' => [['t' => 'date', 'v' => '2026-02-30']],
    'a date with a time' => [['t' => 'date', 'v' => '2026-02-28T00:00:00Z']],
    'a datetime that is not UTC' => [['t' => 'datetime', 'v' => '2026-10-08T12:30:00+02:00']],
]);

it('canonicalises as RFC 8785 does and refuses a float', function () {
    expect(Jcs::encode(['a' => [1, 'x', true, null], 'b' => (object) []]))->toBe('{"a":[1,"x",true,null],"b":{}}')
        ->and(Jcs::encode("\x01\x1f\x7f/"))->toBe("\"\\u0001\\u001f\x7f/\"")
        ->and(Jcs::encode(['z' => 1, 'a' => 2]))->toBe('{"a":2,"z":1}');

    expect(fn () => Jcs::encode(1.5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Jcs::encode("\xff"))->toThrow(InvalidArgumentException::class);
});

it('derives the Workspace key from the digest key file with HKDF-SHA256 and fails closed without a usable key', function () {
    $path = tempnam(sys_get_temp_dir(), 'digest');
    file_put_contents($path, base64_encode(implode('', array_map('chr', range(0, 31)))));

    try {
        $digest = new KeyFileContextDigest(new Repository(['dashflow' => ['secrets' => ['digest_key_path' => ['value' => $path]]]]));

        // HKDF-SHA256, salt empty, info `fkctx|{workspace_id}|v1`: computed outside the application.
        expect(bin2hex((string) $digest->key(FK_WORKSPACE)))->toBe('315af65d33f7f033ace15ef757b5c888b043ab15debf13ad423ef669a19cc87f')
            ->and($digest->key(strtoupper(FK_WORKSPACE)))->toBe($digest->key(FK_WORKSPACE))
            ->and($digest->key('0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2d'))->not->toBe($digest->key(FK_WORKSPACE));

        file_put_contents($path, 'placeholder-not-a-real-key-digest');
        expect($digest->key(FK_WORKSPACE))->toBeNull();
        file_put_contents($path, base64_encode('short'));
        expect($digest->key(FK_WORKSPACE))->toBeNull();
    } finally {
        unlink($path);
    }

    $missing = new KeyFileContextDigest(new Repository(['dashflow' => ['secrets' => ['digest_key_path' => ['value' => '/nonexistent/key-digest']]]]));
    expect($missing->key(FK_WORKSPACE))->toBeNull();
});

it('reads the refresh interval and the dispatch tick without inventing a number', function () {
    expect(SyncSettings::smallest('900,300, 600'))->toBe(300)
        ->and(SyncSettings::smallest('60'))->toBe(60)
        ->and(SyncSettings::smallest(120))->toBe(120)
        ->and(SyncSettings::smallest(null))->toBeNull()
        ->and(SyncSettings::smallest(''))->toBeNull()
        ->and(SyncSettings::smallest('300,x'))->toBeNull()
        ->and(SyncSettings::smallest('0'))->toBeNull()
        ->and(SyncSettings::smallest('1.5'))->toBeNull()
        ->and(SyncSettings::smallest('-5'))->toBeNull()
        ->and(SyncSettings::smallest('300,'))->toBeNull();

    expect(SyncSettings::tick(null))->toBe(5)
        ->and(SyncSettings::tick(''))->toBe(5)
        ->and(SyncSettings::tick('abc'))->toBe(5)
        ->and(SyncSettings::tick(5))->toBe(5)
        ->and(SyncSettings::tick('7'))->toBe(5)
        ->and(SyncSettings::tick(8))->toBe(10)
        ->and(SyncSettings::tick(12))->toBe(10)
        ->and(SyncSettings::tick(25))->toBe(20)
        ->and(SyncSettings::tick(29))->toBe(30)
        ->and(SyncSettings::tick(1))->toBe(1)
        ->and(SyncSettings::tick(60))->toBe(60)
        ->and(SyncSettings::tick(600))->toBe(60);
});
