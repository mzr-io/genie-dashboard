<?php

use Tests\Architecture\Support\Scanner;

$fixtures = __DIR__.'/Fixtures';

it('has no json_decode in Ingestion, RawStore, Mapping or Results', function () {
    expect((new Scanner)->jsonDecodeViolations(dirname(__DIR__, 2).'/app'))->toBe([]);
});

it('fails on json_decode in a banned module and names the file and line', function () use ($fixtures) {
    $violations = (new Scanner)->jsonDecodeViolations("{$fixtures}/json-decode/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('DecodesJson.php.stub:10')
        ->and($violations[0])->toContain('Ingestion');
});

it('allows json_decode in other modules', function () use ($fixtures) {
    expect((new Scanner)->jsonDecodeViolations("{$fixtures}/json-decode-allowed/app"))->toBe([]);
});

it('bans json_decode in exactly Ingestion, RawStore, Mapping and Results', function () {
    expect((require __DIR__.'/dependencies.php')['json_decode_banned'])
        ->toBe(['Ingestion', 'RawStore', 'Mapping', 'Results']);
});

it('fails on a fully-qualified \json_decode in a banned module', function () use ($fixtures) {
    $violations = (new Scanner)->jsonDecodeViolations("{$fixtures}/json-decode-fqn/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('FqnDecode.php.stub:9')
        ->and($violations[0])->toContain('Results');
});

// Story 2.6: the lossless decoder may not delegate to PHP's decoder either.
it('bans json_decode inside app/Platform/Json', function () use ($fixtures) {
    expect((require __DIR__.'/dependencies.php')['json_decode_banned_paths'])->toBe(['Platform/Json']);

    $violations = (new Scanner)->jsonDecodeViolations("{$fixtures}/json-decode-kernel/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('Delegates.php.stub:9')
        ->and($violations[0])->toContain('Platform');
});

it('allows json_decode elsewhere in the kernel', function () use ($fixtures) {
    expect((new Scanner)->jsonDecodeViolations("{$fixtures}/json-decode-kernel-allowed/app"))->toBe([]);
});

it('scans real .php files, not only fixtures, and finds the decoder clean', function () {
    $app = dirname(__DIR__, 2).'/app';

    // Regression guard: with the ban pointed at a module that really calls json_decode, the scanner must see it.
    $rules = (require __DIR__.'/dependencies.php');
    $rules['json_decode_banned'] = ['Connector'];
    $rules['json_decode_banned_paths'] = [];

    expect((new Scanner($rules))->jsonDecodeViolations($app))->not->toBe([])
        ->and((new Scanner)->jsonDecodeViolations($app))->toBe([])
        ->and(is_file($app.'/Platform/Json/LosslessJson.php'))->toBeTrue();
});
