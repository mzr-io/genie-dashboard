<?php

use App\Http\Middleware\RequireActiveMembership;
use App\Modules\Identity\Http\IdleTimeout;
use App\Modules\Identity\Http\SendInvitationsAfterCommit;
use App\Platform\Tenancy\WorkspaceTransaction;

// Story 1.21: invitation mail goes out only after the request transaction commits, so the flushing middleware must
// wrap WorkspaceTransaction (run before it, finish after it) in the API group.
it('puts SendInvitationsAfterCommit before WorkspaceTransaction in the API group', function () {
    $group = app('router')->getMiddlewareGroups()['api'];
    $send = array_search(SendInvitationsAfterCommit::class, $group, true);
    $transaction = array_search(WorkspaceTransaction::class, $group, true);

    expect($send)->not->toBeFalse()->and($transaction)->not->toBeFalse()->and($send)->toBeLessThan($transaction);
});

// Story 1.24: the membership check runs after the idle clock (an idle session is signed out first) and before the
// Workspace transaction opens, in both the web and the API group.
it('puts RequireActiveMembership after IdleTimeout and before WorkspaceTransaction in the web and API groups', function (string $group) {
    $middleware = app('router')->getMiddlewareGroups()[$group];
    $idle = array_search(IdleTimeout::class, $middleware, true);
    $active = array_search(RequireActiveMembership::class, $middleware, true);
    $transaction = array_search(WorkspaceTransaction::class, $middleware, true);

    expect($idle)->not->toBeFalse()->and($active)->not->toBeFalse()->and($transaction)->not->toBeFalse()
        ->and($idle)->toBeLessThan($active)->and($active)->toBeLessThan($transaction);
})->with(['web', 'api']);
