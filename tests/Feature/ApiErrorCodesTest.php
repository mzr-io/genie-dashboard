<?php

use App\Support\Observability\ApiErrorRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('maps each HTTP status to its platform error code', function (int $status, string $code) {
    $response = ApiErrorRenderer::render(new HttpException($status), Request::create('/api/v1/x'));

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getData(true)['error']['code'])->toBe($code);
})->with([
    [403, 'platform.forbidden'],
    [404, 'platform.not_found'],
    [405, 'platform.method_not_allowed'],
    [419, 'platform.csrf_token_mismatch'],
    [422, 'platform.validation_failed'],
    [429, 'platform.too_many_requests'],
    [500, 'platform.server_error'],
    [418, 'platform.http_error'],
]);
