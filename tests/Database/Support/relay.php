<?php

// Child process of the relay concurrency test: relays small batches until no event is pending.
use App\Platform\Outbox\OutboxConsumers;
use App\Platform\Outbox\OutboxRelay;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Database\Fixtures\FileConsumer;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $file] = $argv;

$registry = new OutboxConsumers;
$registry->register(new FileConsumer($file));
$app->instance(OutboxConsumers::class, $registry);

$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
    $app->make(OutboxRelay::class)->relay(3);

    if ((int) DB::connection('system')->table('outbox_events')->whereNull('sent_at')->count() === 0) {
        break;
    }
    usleep(2000);
}
