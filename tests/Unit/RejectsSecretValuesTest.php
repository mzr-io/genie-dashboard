<?php

use App\Http\Middleware\RejectsSecretValues;

it('spots secret-valued fields by name or flag at any depth, and leaves plain settings alone', function (array $payload, bool $refused) {
    expect(RejectsSecretValues::containsSecretValue($payload))->toBe($refused);
})->with([
    'plain' => [['base_url' => 'https://x', 'api_key_name' => 'X-Key', 'api_key_placement' => 'header'], false],
    'empty secret' => [['password' => ''], false],
    'password' => [['confirm_password' => 'x'], true],
    'camelCase token' => [['bearerToken' => 'x'], true],
    'api_key' => [['api_key' => 'x'], true],
    'secrets map' => [['secrets' => ['bearer_token' => 'x']], true],
    'flagged header' => [['headers' => [['name' => 'A', 'secret' => true, 'value' => 'v']]], true],
    'bearer' => [['bearer' => 'x'], true],
    'cipher' => [['ciphertext' => 'x'], true],
    'sealed' => [['sealed_value' => 'x'], true],
    'private key' => [['private-key' => 'x'], true],
    'basic username' => [['basic_username' => 'x'], true],
    'plural' => [['api_keys' => ['x']], true],
    'string flag' => [['headers' => [['name' => 'A', 'secret' => 'true', 'value' => 'v']]], true],
    'numeric flag' => [['headers' => [['name' => 'A', 'secret' => 1, 'value' => 'v']]], true],
    'flag off' => [['headers' => [['name' => 'A', 'secret' => false, 'value' => 'v']]], false],
    'flagged header without value' => [['headers' => [['name' => 'A', 'secret' => true]]], false],
]);
