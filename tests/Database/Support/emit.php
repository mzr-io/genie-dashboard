<?php

// Child process of the concurrency test: emits `$argv[3]` events for one subject, each in its own transaction.
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $workspace, $subject, $count] = $argv;

for ($i = 0; $i < (int) $count; $i++) {
    $app->make(WorkspaceTransaction::class)->run($workspace, function () use ($app, $subject): void {
        $app->make(Outbox::class)->emit(AuditAction::AccessRoleChanged, $subject, ['role' => 'admin']);
        usleep(random_int(0, 5000));
    });
}
