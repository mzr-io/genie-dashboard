<?php

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
