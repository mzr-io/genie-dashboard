<?php

use App\Models\User;

it('signs in a user with a valid email and password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password with a validation error on email and no session', function () {
    $user = User::factory()->create();

    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertNotFound();
});

it('exposes no register, two-factor, passkey or verification routes', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri()->implode("\n");

    expect($uris)->not->toMatch('/register|two-factor|passkey|email\/verif/i');
});
