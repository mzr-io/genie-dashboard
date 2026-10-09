<?php

use Tests\Architecture\Support\ShaperScanner;

// Story 3.1: every Shaper in block-types/ is a pure function.
$fixtures = __DIR__.'/Fixtures';

it('keeps every real Shaper pure', function () {
    $root = dirname(__DIR__, 2);

    expect(is_file($root.'/block-types/kpi-card/v1/Shaper.php'))->toBeTrue()
        ->and((new ShaperScanner)->violations($root))->toBe([]);
});

it('fails a Shaper that does I/O, reads the clock or calls json_decode, naming file and line', function () use ($fixtures) {
    $violations = (new ShaperScanner)->violations("{$fixtures}/shaper-impure");
    $all = implode("\n", $violations);

    expect($violations)->not->toBe([])
        ->and($all)->toContain('Shaper.php.stub:11')
        ->and($all)->toContain('json_decode')
        ->and($all)->toContain('DB')
        ->and($all)->toContain('time()')
        ->and($all)->toContain('file_get_contents')
        ->and($all)->toContain('DateTimeImmutable');
});

it('does not flag a pure Shaper whose comments and strings mention banned calls', function () use ($fixtures) {
    expect((new ShaperScanner)->violations("{$fixtures}/shaper-pure"))->toBe([]);
});

it('fails include/require, eval and variable-function calls', function () use ($fixtures) {
    $all = implode("\n", (new ShaperScanner)->violations("{$fixtures}/shaper-impure2"));

    expect($all)->toContain('Shaper.php.stub:10')->toContain('require')
        ->and($all)->toContain('Shaper.php.stub:11')->toContain('eval')
        ->and($all)->toContain('Shaper.php.stub:13')->toContain('$fn()');
});
