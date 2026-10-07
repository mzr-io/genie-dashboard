<?php

use App\Modules\Identity\Application\InvitationToken;
use App\Modules\Identity\Application\QueuedInvitationCourier;
use App\Modules\Identity\Contracts\InvitationDeliveryFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Story 1.21: the courier makes a 256-bit token whose hash is its SHA-256, keeps the plain token out of dumps, queues
// mail instead of sending it, and logs a failed delivery without the address.
it('makes a 256-bit token, its SHA-256 hash and a secret that never dumps the token', function () {
    $secret = (new QueuedInvitationCourier)->newSecret();

    expect(InvitationToken::wellFormed($secret->token))->toBeTrue()
        ->and($secret->hash)->toBe(hash('sha256', $secret->token))
        ->and(print_r($secret, true))->not->toContain($secret->token)->not->toContain($secret->hash)
        ->and(var_export($secret->__debugInfo(), true))->not->toContain($secret->token);
});

it('sends nothing until it is flushed, and nothing after it is discarded', function () {
    Mail::fake();
    $courier = new QueuedInvitationCourier;
    $courier->activate();

    $courier->deliverAfterCommit('01a114ef-e387-73b4-b7db-55c02ce6a26d', 'bo@example.test', $courier->newSecret(), 'Acme', 'user', now()->addDay());
    Mail::assertNothingSent();

    $courier->discard();
    $courier->flush();
    Mail::assertNothingSent();
});

it('names the invitation, not the address, when a delivery fails, and still tries the others', function () {
    Log::spy();
    Mail::shouldReceive('to')->twice()->andThrow(new RuntimeException('smtp down for bo@example.test'));
    $courier = new QueuedInvitationCourier;
    $courier->activate();
    $first = '01a114ef-e387-73b4-b7db-55c02ce6a26d';

    $courier->deliverAfterCommit($first, 'bo@example.test', $courier->newSecret(), 'Acme', 'user', now()->addDay());
    $courier->deliverAfterCommit('01a114ef-e387-73b4-b7db-55c02ce6a26e', 'cy@example.test', $courier->newSecret(), 'Acme', 'user', now()->addDay());

    try {
        $courier->flush();
        $this->fail('flush should have thrown');
    } catch (InvitationDeliveryFailed $e) {
        expect($e->invitationId)->toBe($first)->and($e->getMessage())->not->toContain('example.test');
    }

    Log::shouldHaveReceived('error')->twice();
});

it('refuses to queue an email when no flush middleware is active, instead of losing it silently', function () {
    Log::spy();
    $courier = new QueuedInvitationCourier;

    expect(fn () => $courier->deliverAfterCommit('01a114ef-e387-73b4-b7db-55c02ce6a26d', 'bo@example.test', $courier->newSecret(), 'Acme', 'user', now()->addDay()))
        ->toThrow(LogicException::class);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'identity.invitation.no_flush_middleware' && ! str_contains(json_encode($context), 'example.test'));
});
