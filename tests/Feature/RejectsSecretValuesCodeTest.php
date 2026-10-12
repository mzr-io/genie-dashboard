<?php

use App\Http\Middleware\RejectsSecretValues;

it('answers with the owning module\'s code: Connector\'s by default, Blocks\' with the blocks parameter (Story 3.3)', function () {
    $request = Request::create('/x', 'POST', ['api_key' => 'sk-1']);
    $next = fn () => response('passed');
    $middleware = new RejectsSecretValues;

    expect(json_decode($middleware->handle($request, $next)->getContent(), true)['error']['code'])->toBe('connector.secret_values_refused')
        ->and(json_decode($middleware->handle($request, $next, 'blocks')->getContent(), true)['error']['code'])->toBe('blocks.secret_values_refused')
        ->and($middleware->handle(Request::create('/x', 'POST', ['name' => 'ok']), $next, 'blocks')->getContent())->toBe('passed');
});
