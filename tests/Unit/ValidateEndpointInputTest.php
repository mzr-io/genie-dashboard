<?php

use App\Modules\Connector\Application\EndpointBodyTemplate;
use App\Modules\Connector\Application\ValidateEndpointInput;
use App\Modules\Connector\Contracts\InvalidDataSource;

// Story 2.9: the Endpoint's validation table. Every refusal names its field and reason; nothing is trimmed or repaired.

function endpointRaw(array $overrides = []): array
{
    return $overrides + [
        'method' => 'GET',
        'path' => '/api/v2/finance/revenue',
        'params' => [],
        'headers' => [],
        'body_template' => null,
        'read_only_query' => false,
        'confirm_read_only' => false,
    ];
}

function endpointInvalid(array $raw): InvalidDataSource
{
    try {
        (new ValidateEndpointInput)->validate($raw);
    } catch (InvalidDataSource $e) {
        return $e;
    }

    throw new RuntimeException('The endpoint was accepted.');
}

function postRaw(array $overrides = []): array
{
    return endpointRaw($overrides + ['method' => 'POST', 'read_only_query' => true, 'confirm_read_only' => true]);
}

it('accepts a GET with placeholders and bindings and classifies the parameters', function () {
    $input = (new ValidateEndpointInput)->validate(endpointRaw([
        'path' => '/customers/{id}/revenue',
        'params' => [
            ['name' => 'id', 'binding' => 'fixed', 'value' => '42'],
            ['name' => 'from', 'binding' => 'date_range_from', 'value' => null],
            ['name' => 'to', 'binding' => 'date_range_to', 'value' => 'ignored'],
            ['name' => 'start', 'binding' => 'period_start', 'value' => ''],
            ['name' => 'end', 'binding' => 'period_end'],
            ['name' => 'currency', 'binding' => 'fixed', 'value' => 'EUR'],
        ],
        'headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance'], ['name' => 'X-From', 'binding' => 'date_range_from']],
    ]));

    expect($input->method)->toBe('GET')
        ->and($input->path->template)->toBe('/customers/{id}/revenue')
        ->and($input->readOnlyQuery)->toBeFalse()
        ->and($input->bodyTemplate)->toBeNull()
        ->and($input->params)->toBe([
            ['name' => 'id', 'binding' => 'fixed', 'value' => '42', 'kind' => 'path'],
            ['name' => 'from', 'binding' => 'date_range_from', 'value' => null, 'kind' => 'query'],
            ['name' => 'to', 'binding' => 'date_range_to', 'value' => null, 'kind' => 'query'],
            ['name' => 'start', 'binding' => 'period_start', 'value' => null, 'kind' => 'query'],
            ['name' => 'end', 'binding' => 'period_end', 'value' => null, 'kind' => 'query'],
            ['name' => 'currency', 'binding' => 'fixed', 'value' => 'EUR', 'kind' => 'query'],
        ])
        ->and($input->headers)->toBe([
            ['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance'],
            ['name' => 'X-From', 'binding' => 'date_range_from', 'value' => null],
        ]);
});

it('refuses every method but GET and POST, case sensitively', function (mixed $method) {
    $e = endpointInvalid(endpointRaw(['method' => $method]));

    expect($e->reasons['method'])->toBe('method-not-allowed')->and($e->errors)->toHaveKey('method');
})->with(['PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'get', 'Post', 'post', '', ' GET', 'GET ', null, 5, [['GET']]]);

it('accepts a POST only as a confirmed read-only query', function () {
    expect((new ValidateEndpointInput)->validate(postRaw())->readOnlyQuery)->toBeTrue();

    $unflagged = endpointInvalid(postRaw(['read_only_query' => false]));
    $unconfirmed = endpointInvalid(postRaw(['confirm_read_only' => false]));
    $absent = endpointInvalid(endpointRaw(['method' => 'POST']));
    $stringy = endpointInvalid(postRaw(['read_only_query' => 'true']));

    expect($unflagged->reasons)->toBe(['read_only_query' => 'post-readonly-required'])
        ->and($unconfirmed->reasons)->toBe(['confirm_read_only' => 'post-confirmation-required'])
        ->and($absent->reasons)->toBe(['read_only_query' => 'post-readonly-required'])
        ->and($stringy->reasons)->toBe(['read_only_query' => 'post-readonly-required']);
});

it('stores a GET as not read-only whatever was posted', function () {
    expect((new ValidateEndpointInput)->validate(endpointRaw(['read_only_query' => true, 'confirm_read_only' => true]))->readOnlyQuery)->toBeFalse();
});

it('names a placeholder that has no declared parameter', function () {
    $e = endpointInvalid(endpointRaw(['path' => '/a/{id}/b/{other}', 'params' => [['name' => 'id', 'binding' => 'fixed', 'value' => '1']]]));

    expect($e->reasons['path'])->toBe('path-param-missing')->and($e->errors['path'][0])->toContain('other')->not->toContain('{id}');
});

it('refuses a fixed value that cannot be a path segment, naming the parameter', function (string $value) {
    $e = endpointInvalid(endpointRaw(['path' => '/a/{id}', 'params' => [['name' => 'id', 'binding' => 'fixed', 'value' => $value]]]));

    expect($e->reasons)->toBe(['params.0.value' => 'param-value-invalid'])->and($e->errors['params.0.value'][0])->toContain('id');
})->with(['a/b', '.', '..', '/']);

it('lets a query parameter hold a value a path parameter could not', function () {
    expect((new ValidateEndpointInput)->validate(endpointRaw(['params' => [['name' => 'q', 'binding' => 'fixed', 'value' => 'a/b']]]))->params[0]['kind'])->toBe('query');
});

it('refuses bad parameters with a field error each', function (array $params, string $field, string $reason) {
    $e = endpointInvalid(endpointRaw(['params' => $params]));

    expect($e->reasons[$field])->toBe($reason);
})->with([
    'a fixed value is required' => [[['name' => 'a', 'binding' => 'fixed']], 'params.0.value', 'param-value-required'],
    'an empty fixed value' => [[['name' => 'a', 'binding' => 'fixed', 'value' => '']], 'params.0.value', 'param-value-required'],
    'a number is not a string' => [[['name' => 'a', 'binding' => 'fixed', 'value' => 5]], 'params.0.value', 'param-value-required'],
    'a control character' => [[['name' => 'a', 'binding' => 'fixed', 'value' => "x\x00y"]], 'params.0.value', 'param-value-invalid'],
    'a value that is too long' => [[['name' => 'a', 'binding' => 'fixed', 'value' => str_repeat('x', 2049)]], 'params.0.value', 'param-value-too-long'],
    'a bad name' => [[['name' => 'a b', 'binding' => 'fixed', 'value' => 'x']], 'params.0.name', 'param-name-invalid'],
    'a name with an ampersand' => [[['name' => 'a&b', 'binding' => 'fixed', 'value' => 'x']], 'params.0.name', 'param-name-invalid'],
    'an empty name' => [[['name' => '', 'binding' => 'fixed', 'value' => 'x']], 'params.0.name', 'param-name-invalid'],
    'a duplicate name' => [[['name' => 'a', 'binding' => 'fixed', 'value' => 'x'], ['name' => 'a', 'binding' => 'fixed', 'value' => 'y']], 'params.1.name', 'param-name-duplicate'],
    'user context is not a binding of its own' => [[['name' => 'a', 'binding' => 'user_context', 'value' => null]], 'params.0.binding', 'binding-invalid'],
    'a user attribute needs a key' => [[['name' => 'a', 'binding' => 'user_attribute']], 'params.0.binding', 'binding-attribute-unknown'],
    'a user attribute key that is not defined' => [[['name' => 'a', 'binding' => 'user_attribute', 'value' => 'nope']], 'params.0.binding', 'binding-attribute-unknown'],
    'an unknown binding' => [[['name' => 'a', 'binding' => 'magic']], 'params.0.binding', 'binding-invalid'],
    'no binding' => [[['name' => 'a']], 'params.0.binding', 'binding-invalid'],
    'not a list' => [['a' => 1], 'params', 'params-invalid'],
]);

it('keeps the original row index on errors after a dropped row', function () {
    $e = endpointInvalid(endpointRaw([
        'path' => '/a/{id}',
        'params' => [['name' => 'a b', 'binding' => 'fixed', 'value' => 'x'], ['name' => 'id', 'binding' => 'fixed', 'value' => 'a/b']],
    ]));

    expect($e->reasons)->toBe(['params.0.name' => 'param-name-invalid', 'params.1.value' => 'param-value-invalid']);
});

it('refuses too many parameters and too many headers', function () {
    $params = array_map(fn (int $i): array => ['name' => "p{$i}", 'binding' => 'fixed', 'value' => 'x'], range(1, 26));
    $headers = array_map(fn (int $i): array => ['name' => "X-H{$i}", 'binding' => 'fixed', 'value' => 'x'], range(1, 26));

    expect(endpointInvalid(endpointRaw(['params' => $params]))->reasons)->toBe(['params' => 'too-many-params'])
        ->and(endpointInvalid(endpointRaw(['headers' => $headers]))->reasons)->toBe(['headers' => 'too-many-headers']);
});

it('refuses a bad header with a field error and nothing else stored', function (array $headers, string $field, string $reason) {
    $e = endpointInvalid(endpointRaw(['headers' => $headers]));

    expect($e->reasons[$field])->toBe($reason);
})->with([
    'a line feed in the value' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\nb"]], 'headers.0.value', 'header-value-invalid'],
    'a carriage return in the value' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\rb"]], 'headers.0.value', 'header-value-invalid'],
    'CRLF header injection' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\r\nX-Evil: 1"]], 'headers.0.value', 'header-value-invalid'],
    'a tab' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\tb"]], 'headers.0.value', 'header-value-invalid'],
    'a null byte' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\x00b"]], 'headers.0.value', 'header-value-invalid'],
    'a delete character' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\x7fb"]], 'headers.0.value', 'header-value-invalid'],
    'non ascii' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => "caf\xc3\xa9"]], 'headers.0.value', 'header-value-invalid'],
    'a missing value' => [[['name' => 'X-A', 'binding' => 'fixed']], 'headers.0.value', 'param-value-required'],
    'a bad name' => [[['name' => 'X A', 'binding' => 'fixed', 'value' => 'x']], 'headers.0.name', 'header-name-invalid'],
    'a name with a colon' => [[['name' => 'X-A:', 'binding' => 'fixed', 'value' => 'x']], 'headers.0.name', 'header-name-invalid'],
    'Host' => [[['name' => 'Host', 'binding' => 'fixed', 'value' => 'x']], 'headers.0.name', 'header-name-reserved'],
    'content-length' => [[['name' => 'content-length', 'binding' => 'fixed', 'value' => '1']], 'headers.0.name', 'header-name-reserved'],
    'Authorization' => [[['name' => 'Authorization', 'binding' => 'fixed', 'value' => 'x']], 'headers.0.name', 'header-name-reserved'],
    'cookie' => [[['name' => 'COOKIE', 'binding' => 'fixed', 'value' => 'x']], 'headers.0.name', 'header-name-reserved'],
    'proxy-authorization' => [[['name' => 'Proxy-Authorization', 'binding' => 'fixed', 'value' => 'x']], 'headers.0.name', 'header-name-reserved'],
    'a duplicate, in any case' => [[['name' => 'X-A', 'binding' => 'fixed', 'value' => 'x'], ['name' => 'x-a', 'binding' => 'fixed', 'value' => 'y']], 'headers.1.name', 'header-name-duplicate'],
    'a header attribute key that is not defined' => [[['name' => 'X-A', 'binding' => 'user_attribute', 'value' => 'nope']], 'headers.0.binding', 'binding-attribute-unknown'],
    'not a list' => [['a' => 1], 'headers', 'headers-invalid'],
]);

it('does not look at the value of a date or period header, which is stored null', function () {
    $input = (new ValidateEndpointInput)->validate(endpointRaw(['headers' => [['name' => 'X-From', 'binding' => 'period_start', 'value' => "bad\nvalue"]]]));

    expect($input->headers)->toBe([['name' => 'X-From', 'binding' => 'period_start', 'value' => null]]);
});

it('reports every error at once', function () {
    $e = endpointInvalid(['method' => 'PUT', 'path' => 'https://x.test/a', 'params' => [['name' => 'a b', 'binding' => 'x']], 'headers' => [['name' => 'Host', 'binding' => 'fixed', 'value' => 'x']]]);

    expect(array_keys($e->reasons))->toBe(['method', 'path', 'params.0.name', 'params.0.binding', 'headers.0.name']);
});

describe('body template', function () {
    it('accepts a POST body with whole-value parameters and keeps every number as written', function () {
        $input = (new ValidateEndpointInput)->validate(postRaw([
            'params' => [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'limit', 'binding' => 'fixed', 'value' => '10']],
            'body_template' => '{"range":{"from":{"$param":"from"}},"limit":{"$param":"limit"},"rate":1.10,"big":12345678901234567890.12345,"exp":1E+2,"on":true,"none":null,"tags":["a",{"$param":"limit"}],"empty":{},"list":[]}',
        ]));

        expect(array_column($input->params, 'kind', 'name'))->toBe(['from' => 'body', 'limit' => 'body'])
            ->and(EndpointBodyTemplate::text(json_decode(json_encode($input->bodyTemplate))))
            ->toBe('{"big":12345678901234567890.12345,"empty":{},"exp":1E+2,"limit":{"$param":"limit"},"list":[],"none":null,"on":true,"range":{"from":{"$param":"from"}},"rate":1.10,"tags":["a",{"$param":"limit"}]}');
    });

    it('treats a path placeholder as a path parameter even when the body names it', function () {
        $input = (new ValidateEndpointInput)->validate(postRaw([
            'path' => '/a/{id}',
            'params' => [['name' => 'id', 'binding' => 'fixed', 'value' => '1']],
            'body_template' => '{"id":{"$param":"id"}}',
        ]));

        expect($input->params[0]['kind'])->toBe('path');
    });

    it('refuses what is not a typed whole-value parameter', function (string $template, string $reason) {
        $e = endpointInvalid(postRaw(['params' => [['name' => 'from', 'binding' => 'date_range_from']], 'body_template' => $template]));

        expect($e->reasons)->toBe(['body_template' => $reason]);
    })->with([
        'a parameter inside a string' => ['{"a":"from {from} to"}', 'body-template-interpolation'],
        'a double brace' => ['{"a":"{{from}}"}', 'body-template-interpolation'],
        'a shell style reference' => ['{"a":"${from}"}', 'body-template-interpolation'],
        'the marker inside a string' => ['{"a":"x $param y"}', 'body-template-interpolation'],
        'a parameter as a key' => ['{"{from}":1}', 'body-template-interpolation'],
        'the marker as a key with others' => ['{"$param":"from","x":1}', 'body-template-param-invalid'],
        'the marker with a number' => ['{"$param":5}', 'body-template-param-invalid'],
        'a reserved key' => ['{"$number":"1"}', 'body-template-interpolation'],
        'another dollar key' => ['{"$ref":"x"}', 'body-template-interpolation'],
        'an unknown parameter' => ['{"a":{"$param":"nope"}}', 'body-template-param-unknown'],
        'not JSON' => ['{"a":', 'body-template-invalid'],
        'a duplicate key' => ['{"a":1,"a":2}', 'body-template-invalid'],
        'a trailing comma' => ['{"a":1,}', 'body-template-invalid'],
        'NaN' => ['{"a":NaN}', 'body-template-invalid'],
        'the null character' => ['{"a":"x\\u0000y"}', 'body-template-invalid'],
        'too deep' => [str_repeat('[', 17).str_repeat(']', 17), 'body-template-too-deep'],
        'too long' => ['"'.str_repeat('a', 65536).'"', 'body-template-too-long'],
    ]);

    it('accepts a GraphQL body whose braces are not declared parameters', function () {
        $input = (new ValidateEndpointInput)->validate(postRaw(['body_template' => '{"query":"{revenue{total}}"}']));

        expect(EndpointBodyTemplate::text(json_decode(json_encode($input->bodyTemplate))))->toBe('{"query":"{revenue{total}}"}');
    });

    it('refuses a declared parameter written in braces inside a string or key, and a bare null template', function () {
        $params = [['name' => 'from', 'binding' => 'date_range_from']];

        expect(endpointInvalid(postRaw(['params' => $params, 'body_template' => '{"q":"since {from}"}']))->reasons)->toBe(['body_template' => 'body-template-interpolation'])
            ->and(endpointInvalid(postRaw(['params' => $params, 'body_template' => '{"{from}":1}']))->reasons)->toBe(['body_template' => 'body-template-interpolation'])
            ->and(endpointInvalid(postRaw(['body_template' => 'null']))->reasons)->toBe(['body_template' => 'body-template-invalid']);
    });

    it('names the unknown parameter in the message', function () {
        $e = endpointInvalid(postRaw(['body_template' => '{"a":{"$param":"nope"}}']));

        expect($e->errors['body_template'][0])->toContain('nope');
    });

    it('refuses a body template on a GET, and a body that is not text', function () {
        expect(endpointInvalid(endpointRaw(['body_template' => '{}']))->reasons)->toBe(['body_template' => 'body-template-not-allowed'])
            ->and(endpointInvalid(postRaw(['body_template' => ['a' => 1]]))->reasons)->toBe(['body_template' => 'body-template-invalid']);
    });

    it('treats an absent or empty body template as none', function () {
        expect((new ValidateEndpointInput)->validate(postRaw(['body_template' => '']))->bodyTemplate)->toBeNull()
            ->and((new ValidateEndpointInput)->validate(postRaw(['body_template' => null]))->bodyTemplate)->toBeNull();
    });

    it('stores a scalar template and keeps a key that is a number', function () {
        $input = (new ValidateEndpointInput)->validate(postRaw(['body_template' => '{"1":"x","0":"y"}']));

        expect(EndpointBodyTemplate::text(json_decode(json_encode($input->bodyTemplate))))->toBe('{"0":"y","1":"x"}');
    });
});

// Story 2.10: a parameter inside a JSON array of the body template is a body parameter, too (it was once taken for a query one).
it('classifies a parameter used inside an array of the body template as a body parameter', function () {
    $input = (new ValidateEndpointInput)->validate([
        'method' => 'POST', 'path' => '/search', 'read_only_query' => true, 'confirm_read_only' => true,
        'params' => [['name' => 'team', 'binding' => 'fixed', 'value' => 'a'], ['name' => 'debug', 'binding' => 'fixed', 'value' => '1']],
        'body_template' => '{"filters":[{"$param":"team"}]}',
    ]);

    expect(array_column($input->params, 'kind', 'name'))->toBe(['team' => 'body', 'debug' => 'query']);
});

// Story 2.13: user-context bindings. The stored row holds a kind and, for an attribute, the defined key id, never a user's value.
it('accepts the four user bindings, derives requires_user_context and stores only the key id', function () {
    $input = (new ValidateEndpointInput)->validate(endpointRaw([
        'params' => [
            ['name' => 'uid', 'binding' => 'user_id', 'value' => 'client-supplied'],
            ['name' => 'mail', 'binding' => 'user_email'],
            ['name' => 'grp', 'binding' => 'user_group', 'value' => 'ignored'],
            ['name' => 'region', 'binding' => 'user_attribute', 'value' => 'region'],
        ],
        'headers' => [['name' => 'X-User', 'binding' => 'user_id']],
    ]), ['region', 'team']);

    expect($input->requiresUserContext())->toBeTrue()
        ->and($input->scopeByCaller)->toBeFalse()
        ->and(array_column($input->params, 'value', 'name'))->toBe(['uid' => null, 'mail' => null, 'grp' => null, 'region' => 'region'])
        ->and($input->headers[0]['value'])->toBeNull();
});

it('does not require user context without a user binding', function () {
    $input = (new ValidateEndpointInput)->validate(endpointRaw(['params' => [['name' => 'a', 'binding' => 'fixed', 'value' => 'x']]]));

    expect($input->requiresUserContext())->toBeFalse();
});

it('refuses an attribute key the Workspace does not define, on the Binding select', function () {
    $raw = endpointRaw(['params' => [['name' => 'a', 'binding' => 'user_attribute', 'value' => 'region']]]);

    expect(fn () => (new ValidateEndpointInput)->validate($raw, ['team']))->toThrow(InvalidDataSource::class);
    expect(endpointInvalid($raw)->reasons)->toBe(['params.0.binding' => 'binding-attribute-unknown']);
});

it('accepts scope_by_caller only with a user binding', function () {
    $with = (new ValidateEndpointInput)->validate(endpointRaw(['scope_by_caller' => true, 'params' => [['name' => 'a', 'binding' => 'user_id']]]));

    expect($with->scopeByCaller)->toBeTrue()
        ->and(endpointInvalid(endpointRaw(['scope_by_caller' => true, 'params' => [['name' => 'a', 'binding' => 'fixed', 'value' => 'x']]]))->reasons)->toBe(['scope_by_caller' => 'scope-requires-user-context'])
        ->and(endpointInvalid(endpointRaw(['scope_by_caller' => 'yes']))->reasons)->toBe(['scope_by_caller' => 'scope-invalid']);
});
