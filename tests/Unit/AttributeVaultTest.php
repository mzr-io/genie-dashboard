<?php

use App\Modules\Access\Application\AttributeValueRules;
use App\Modules\Access\Contracts\AttributesUnavailable;
use App\Modules\Access\Infrastructure\SodiumAttributeVault;
use Illuminate\Config\Repository;

// Story 2.12: the value shapes by type, and the vault that seals them and computes the blind index.
function avVault(?string $data = null, ?string $digest = null, array &$files = []): SodiumAttributeVault
{
    $data ??= base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $digest ??= base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $files = [tempnam(sys_get_temp_dir(), 'av-data'), tempnam(sys_get_temp_dir(), 'av-digest')];
    file_put_contents($files[0], $data);
    file_put_contents($files[1], $digest);

    return new SodiumAttributeVault(new Repository(['dashflow' => ['secrets' => ['data_key_path' => ['value' => $files[0]], 'digest_key_path' => ['value' => $files[1]]]]]));
}

it('trims, then accepts and refuses values by type', function (string $type, mixed $raw, string|false $expected, string $reason = '') {
    if ($expected === false) {
        expect(fn () => AttributeValueRules::normalise($type, $raw))->toThrow(InvalidArgumentException::class, $reason);

        return;
    }

    expect(AttributeValueRules::normalise($type, $raw))->toBe($expected);
})->with([
    ['text', '  North  ', 'North'],
    ['text', 'Zürich 北', 'Zürich 北'],
    ['text', ' ', false, 'empty'],
    ['text', null, false, 'empty'],
    ['text', "a\tb", false, 'invalid'],
    ['text', "a\u{200B}b", false, 'invalid'],
    ['text', str_repeat('a', 257), false, 'too_long'],
    ['identifier', 'EMP-001.a_b', 'EMP-001.a_b'],
    ['identifier', 'a b', false, 'invalid'],
    ['identifier', 'é', false, 'invalid'],
    ['integer', '0', '0'],
    ['integer', '-5', '-5'],
    ['integer', '-0', false, 'invalid'],
    ['integer', '01', false, 'invalid'],
    ['integer', '1.5', false, 'invalid'],
    ['integer', '999999999999999999', '999999999999999999'],
    ['integer', '1000000000000000000', false, 'invalid'],
    ['integer', 12, false, 'invalid'],
    ['other', 'x', false, 'invalid'],
]);

it('seals and opens a value bound to its Workspace, member and key id', function () {
    $vault = avVault();
    $sealed = $vault->seal('W1', 'M1', 'region', 'North');

    expect($sealed)->not->toContain('North')
        ->and($vault->open('W1', 'M1', 'region', $sealed))->toBe('North')
        ->and($vault->seal('W1', 'M1', 'region', 'North'))->not->toBe($sealed);

    foreach ([['W2', 'M1', 'region'], ['W1', 'M2', 'region'], ['W1', 'M1', 'team']] as $other) {
        expect(fn () => $vault->open(...[...$other, $sealed]))->toThrow(AttributesUnavailable::class);
    }

    expect(fn () => $vault->open('W1', 'M1', 'region', 'short'))->toThrow(AttributesUnavailable::class);
});

it('computes a keyed hex blind index per Workspace and key', function () {
    $digest = random_bytes(32);
    $vault = avVault(null, base64_encode($digest));

    expect($vault->blindIndex('W1', 'region', 'North'))->toBe(hash_hmac('sha256', 'attr|w1|region|North', $digest))
        ->and($vault->blindIndex('W1', 'region', 'North'))->not->toBe($vault->blindIndex('W1', 'team', 'North'))
        ->and($vault->blindIndex('W1', 'region', 'North'))->not->toBe($vault->blindIndex('W2', 'region', 'North'));
});

it('fails closed when either key is unusable and never names key material in the message', function (?string $data, ?string $digest) {
    $good = base64_encode(str_repeat('k', 32));
    $vault = avVault($data ?? $good, $digest ?? $good);

    try {
        $vault->assertAvailable();
        $failed = false;
    } catch (AttributesUnavailable $e) {
        $failed = true;
        expect($e->getMessage())->not->toContain($good);
    }

    expect($failed)->toBeTrue();
})->with([['', null], ['not base64!!', null], [null, ''], [null, base64_encode('short')]]);
