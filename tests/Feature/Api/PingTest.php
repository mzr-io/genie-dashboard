<?php

use App\Models\User;

it('rejects unauthenticated requests with 401', function () {
    $this->getJson('/api/v1/ping')->assertUnauthorized();
});

it('answers a signed-in stateful request', function () {
    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'http://localhost:8000')
        ->getJson('/api/v1/ping')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('rejects a stateful state-changing request without a CSRF token with 419', function () {
    // CSRF checks are skipped when APP_ENV is "testing"; run this request as a real environment.
    app()['env'] = 'local';

    $this->actingAs(User::factory()->create())
        ->withHeader('Referer', 'http://localhost:8000')
        ->postJson('/api/v1/ping')
        ->assertStatus(419);
});

it('accepts a state-changing request that carries the X-XSRF-TOKEN issued by /sanctum/csrf-cookie', function () {
    app()['env'] = 'local';

    $boot = $this->withHeader('Referer', 'http://localhost:8000')->get('/sanctum/csrf-cookie');
    $xsrf = $boot->getCookie('XSRF-TOKEN', false)->getValue();
    $session = $boot->getCookie(config('session.cookie'), false)->getValue();

    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookie(config('session.cookie'), $session)
        ->withHeaders(['Referer' => 'http://localhost:8000', 'X-XSRF-TOKEN' => $xsrf])
        ->postJson('/api/v1/ping')
        ->assertOk();
});

it('rejects a state-changing request with a forged X-XSRF-TOKEN with 419', function () {
    app()['env'] = 'local';

    $boot = $this->withHeader('Referer', 'http://localhost:8000')->get('/sanctum/csrf-cookie');
    $session = $boot->getCookie(config('session.cookie'), false)->getValue();

    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookie(config('session.cookie'), $session)
        ->withHeaders(['Referer' => 'http://localhost:8000', 'X-XSRF-TOKEN' => 'forged'])
        ->postJson('/api/v1/ping')
        ->assertStatus(419);
});

it('renders a JSON 401 for api routes even without an Accept header', function () {
    $this->get('/api/v1/ping')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json');
});
