<?php

namespace Tests\Database\Fixtures;

use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;

/** Appends `subject seq` lines to a file under an exclusive lock: usable from several processes. */
final class FileConsumer implements OutboxConsumer
{
    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'test.file';
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return true;
    }

    public function handle(OutboxEnvelope $event): void
    {
        file_put_contents($this->path, $event->subject.' '.$event->subjectSeq."\n", FILE_APPEND | LOCK_EX);
    }
}
