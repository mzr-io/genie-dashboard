<?php

use Tests\Architecture\Support\Scanner;

$fixtures = __DIR__.'/Fixtures';

it('lets only NativeCurlClient make an outbound request in the real tree', function () {
    expect((new Scanner)->egressViolations(dirname(__DIR__, 2).'/app'))->toBe([]);
});

it('catches curl, the Http facade and a file_get_contents URL outside NativeCurlClient, and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->egressViolations("{$fixtures}/egress-bypass/app");

    expect($violations)->toHaveCount(3)
        ->and($violations[0])->toContain('Bypass.php.stub')
        ->and(implode(' ', $violations))->toContain('Http::')->toContain('file_get_contents(')->toContain('curl_init(');
});

it('allows curl inside NativeCurlClient', function () use ($fixtures) {
    expect((new Scanner)->egressViolations("{$fixtures}/egress-clean/app"))->toBe([]);
});
