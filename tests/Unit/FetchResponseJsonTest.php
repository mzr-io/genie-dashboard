<?php

use App\Modules\Connector\Contracts\FetchResponse;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\InvalidLimitSetting;
use Illuminate\Config\Repository;

// Story 2.6: the JSON check every 2xx answer goes through.

function frJson(string $body, ?string $type): FetchResponse
{
    return new FetchResponse(200, $type === null ? [] : ['content-type' => [$type]], $body, strlen($body), 1);
}

it('accepts application/json and application/*+json in any case, with an optional utf-8 charset, and decodes losslessly', function (string $type) {
    $value = frJson('{"total":1.10}', $type)->json();

    expect($value->get('total'))->toBeInstanceOf(DecimalLiteral::class)->and((string) $value->get('total'))->toBe('1.10');
})->with(['application/json', 'Application/JSON', 'application/json; charset=utf-8', 'application/json;charset="UTF-8"', 'application/vnd.api+json', 'application/problem+json; charset=UTF-8', 'application/json; profile=x']);

it('rejects a wrong or missing Content-Type, another charset and an empty body, with a reason and no retry', function (?string $type, string $body, string $reason) {
    try {
        frJson($body, $type)->json();
        $caught = null;
    } catch (NotJsonResponse $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(NotJsonResponse::class)
        ->and($caught->reason)->toBe($reason)
        ->and($caught->retryable)->toBeFalse()
        ->and($caught->code()->value)->toBe('connector.not_json');
})->with([
    'html' => ['text/html', '<html></html>', 'content_type'],
    'plain text' => ['text/plain', '{}', 'content_type'],
    'missing type' => [null, '{}', 'content_type'],
    'text json' => ['text/json', '{}', 'content_type'],
    'other charset' => ['application/json; charset=iso-8859-1', '{}', 'content_type'],
    'prefix trick' => ['application/jsonp', '{}', 'content_type'],
    'empty body' => ['application/json', '', 'empty'],
    'trailing comma' => ['application/json', '[1,]', 'parse'],
    'bom' => ['application/json', "\xEF\xBB\xBF{}", 'parse'],
    'duplicate key' => ['application/json', '{"a":1,"a":2}', 'parse'],
    'bad utf-8' => ['application/json', "[\"\xff\"]", 'parse'],
]);

it('rejects a body deeper than the configured depth limit as not JSON', function () {
    frDepth('3');

    expect(fn () => frJson('[[[[]]]]', 'application/json')->json())->toThrow(NotJsonResponse::class, 'too_deep');
});
afterEach(fn () => app()->forgetInstance('config'));

function frDepth(mixed $value): void
{
    app()->instance('config', new Repository(['dashflow' => ['tunables' => ['guards' => ['depth_limit' => ['value' => $value]]]]]));
}

it('rejects unless exactly one Content-Type value is present', function () {
    $two = new FetchResponse(200, ['content-type' => ['application/json', 'text/html']], '{}', 2, 1);
    $none = new FetchResponse(200, ['content-type' => []], '{}', 2, 1);

    expect(fn () => $two->json())->toThrow(NotJsonResponse::class, 'content_type')
        ->and(fn () => $two->assertJson())->toThrow(NotJsonResponse::class, 'content_type')
        ->and(fn () => $none->json())->toThrow(NotJsonResponse::class, 'content_type');
});

it('assertJson() judges like json() without building the value', function () {
    frJson('{"a":[1.10]}', 'application/json')->assertJson();

    expect(fn () => frJson('{"a":1,"a":2}', 'application/json')->assertJson())->toThrow(NotJsonResponse::class, 'parse');
});

it('fails closed on a malformed depth limit', function () {
    frDepth('10MB');

    expect(fn () => frJson('{}', 'application/json')->assertJson())->toThrow(InvalidLimitSetting::class);
});
