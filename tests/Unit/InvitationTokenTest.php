<?php

use App\Modules\Identity\Application\InvitationToken;

it('generates 256 random bits as 43 URL-safe characters, different each time', function () {
    $a = InvitationToken::generate();
    $b = InvitationToken::generate();

    expect($a)->toMatch(InvitationToken::PATTERN)->and($a)->not->toBe($b)
        ->and(strlen((string) base64_decode(strtr($a, '-_', '+/').'=', true)))->toBe(32);
});

it('hashes with SHA-256 and recognises a malformed token', function () {
    expect(InvitationToken::hash('abc'))->toBe(hash('sha256', 'abc'))->and(strlen(InvitationToken::hash('abc')))->toBe(64)
        ->and(InvitationToken::wellFormed('short'))->toBeFalse()
        ->and(InvitationToken::wellFormed(InvitationToken::generate()))->toBeTrue();
});
