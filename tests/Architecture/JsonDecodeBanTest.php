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
