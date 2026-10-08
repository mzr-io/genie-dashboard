<?php

use App\Modules\Connector\Contracts\EndpointPath;
use App\Modules\Connector\Contracts\InvalidEndpointPath;
use App\Modules\Connector\Contracts\InvalidPathValue;

// Story 2.9: the path is parsed once into an AST of literal and `{name}` segments; rendering percent-encodes each value as
// one segment and refuses a value that could change the path's shape.

function pathReason(mixed $input): ?string
{
    try {
        EndpointPath::parse($input);
    } catch (InvalidEndpointPath $e) {
        return $e->reason;
    }

    return null;
}

it('parses a path into literal and placeholder segments, once', function () {
    $path = EndpointPath::parse('/api/v2/customers/{id}/orders/{order_id}');

    expect($path->template)->toBe('/api/v2/customers/{id}/orders/{order_id}')
        ->and($path->ast)->toBe([
            ['type' => 'literal', 'value' => 'api'],
            ['type' => 'literal', 'value' => 'v2'],
            ['type' => 'literal', 'value' => 'customers'],
            ['type' => 'param', 'name' => 'id'],
            ['type' => 'literal', 'value' => 'orders'],
            ['type' => 'param', 'name' => 'order_id'],
        ])
        ->and($path->placeholders())->toBe(['id', 'order_id']);
});

it('accepts a plain GET path and a literal with encoded characters', function (string $input) {
    expect(pathReason($input))->toBeNull();
})->with([
    'finance revenue' => ['/api/v2/finance/revenue'],
    'one segment' => ['/health'],
    'an encoded space' => ['/a%20b/c'],
    'a colon and an at sign' => ['/users:search/@me'],
    'a dotted name' => ['/v1.2/data.json'],
    'a double encoded percent is only text' => ['/a%2541'],
]);

it('refuses a path that is not a path under the base URL, with its reason', function (mixed $input, string $reason) {
    expect(pathReason($input))->toBe($reason);
})->with([
    'not a string' => [42, 'path-required'],
    'empty' => ['', 'path-required'],
    'absolute https' => ['https://other.host/x', 'path-absolute'],
    'absolute http' => ['http://other.host/x', 'path-absolute'],
    'another scheme' => ['javascript:alert(1)', 'path-absolute'],
    'protocol relative' => ['//host/x', 'path-protocol-relative'],
    'no leading slash' => ['api/v2/x', 'path-leading-slash'],
    'a backslash' => ['/a\\b', 'path-backslash'],
    'a query string' => ['/a?b=1', 'path-query'],
    'a fragment' => ['/a#b', 'path-fragment'],
    'a control character' => ["/a\x01b", 'path-invalid-characters'],
    'a line break' => ["/a\nb", 'path-invalid-characters'],
    'a space' => ['/a b', 'path-invalid-characters'],
    'non ascii' => ['/caf'."\xc3\xa9", 'path-invalid-characters'],
    'a bare percent' => ['/a%zz', 'path-invalid-characters'],
    'an empty segment' => ['/a//b', 'path-empty-segment'],
    'a trailing slash' => ['/a/', 'path-empty-segment'],
    'the root' => ['/', 'path-empty-segment'],
    'a dot segment' => ['/a/./b', 'path-dot-segment'],
    'a dot dot segment' => ['/a/../b', 'path-dot-segment'],
    'an encoded dot dot' => ['/a/%2e%2E/b', 'path-dot-segment'],
    'an encoded dot' => ['/a/%2e/b', 'path-dot-segment'],
    'a double encoded dot dot' => ['/a/%252e%252e/b', 'path-dot-segment'],
    'an encoded slash' => ['/a%2fb', 'path-encoded-separator'],
    'an encoded slash in capitals' => ['/a%2Fb', 'path-encoded-separator'],
    'an encoded backslash' => ['/a%5cb', 'path-encoded-separator'],
    'an encoded null' => ['/a%00b', 'path-encoded-separator'],
    'nine layers of encoding' => ['/a/%25252525252525252e', 'path-dot-segment'],
    'a dot dot with a path parameter' => ['/a/..;x/b', 'path-dot-segment'],
    'a dot with a path parameter' => ['/a/.;x', 'path-dot-segment'],
    'an encoded dot dot with a path parameter' => ['/a/%2e%2e;jsessionid=1', 'path-dot-segment'],
    'a placeholder with a bad name' => ['/a/{1id}', 'path-placeholder-invalid'],
    'a placeholder with a dash' => ['/a/{my-id}', 'path-placeholder-invalid'],
    'an empty placeholder' => ['/a/{}', 'path-placeholder-invalid'],
    'a placeholder inside a segment' => ['/a/x{id}', 'path-placeholder-invalid'],
    'a stray brace' => ['/a/{id', 'path-placeholder-invalid'],
    'a repeated placeholder' => ['/a/{id}/b/{id}', 'path-placeholder-duplicate'],
    'too long' => ['/'.str_repeat('a', EndpointPath::MAX_LENGTH), 'path-too-long'],
    'too many segments' => ['/'.implode('/', array_fill(0, EndpointPath::MAX_SEGMENTS + 1, 'a')), 'path-too-many-segments'],
]);

it('accepts a placeholder name of 64 characters and refuses 65', function () {
    expect(pathReason('/{'.str_repeat('a', 64).'}'))->toBeNull()
        ->and(pathReason('/{'.str_repeat('a', 65).'}'))->toBe('path-placeholder-invalid');
});

it('renders each value as one percent-encoded segment', function (string $value, string $segment) {
    $path = EndpointPath::parse('/customers/{id}/orders');

    expect(EndpointPath::render($path->ast, ['id' => $value]))->toBe("/customers/{$segment}/orders");
})->with([
    'plain' => ['42', '42'],
    'a space' => ['a b', 'a%20b'],
    'a question mark' => ['a?b', 'a%3Fb'],
    'a hash' => ['a#b', 'a%23b'],
    'a backslash' => ['a\\b', 'a%5Cb'],
    'a percent is encoded, never decoded' => ['%2e%2e', '%252e%252e'],
    'a dot inside a name' => ['a.b', 'a.b'],
    'three dots' => ['...', '...'],
    'non ascii' => ["caf\xc3\xa9", 'caf%C3%A9'],
    'a colon' => ['a:b', 'a%3Ab'],
]);

it('refuses a value that could change the shape of the path, naming the parameter and never the value', function (mixed $value) {
    $path = EndpointPath::parse('/customers/{id}');

    try {
        EndpointPath::render($path->ast, ['id' => $value]);
        $this->fail('The value was rendered.');
    } catch (InvalidPathValue $e) {
        expect($e->parameter)->toBe('id')
            ->and($e->getMessage())->toContain('id');
    }
})->with([
    'a slash' => ['a/b'],
    'a leading slash' => ['/etc'],
    'a dot' => ['.'],
    'a dot dot' => ['..'],
    'empty' => [''],
    'an integer' => [7],
    'null' => [null],
]);

it('refuses a missing value, naming the placeholder', function () {
    $path = EndpointPath::parse('/a/{one}/b/{two}');

    expect(fn () => EndpointPath::render($path->ast, ['one' => 'x']))->toThrow(InvalidPathValue::class, 'two');
});

it('says which values can be one segment', function () {
    expect(EndpointPath::valueAllowed('abc'))->toBeTrue()
        ->and(EndpointPath::valueAllowed('...'))->toBeTrue()
        ->and(EndpointPath::valueAllowed(''))->toBeFalse()
        ->and(EndpointPath::valueAllowed('.'))->toBeFalse()
        ->and(EndpointPath::valueAllowed('..'))->toBeFalse()
        ->and(EndpointPath::valueAllowed('a/b'))->toBeFalse();
});

it('renders a stored AST the same way', function () {
    $path = EndpointPath::parse('/v1/{id}');
    $stored = EndpointPath::fromStored($path->template, json_decode(json_encode($path->ast), true));

    expect(EndpointPath::render($stored->ast, ['id' => 'x y']))->toBe('/v1/x%20y');
});
