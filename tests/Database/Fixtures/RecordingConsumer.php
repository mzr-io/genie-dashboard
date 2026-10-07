<?php

namespace Tests\Database\Fixtures;

use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;

/** Records what it received and the Workspace context it saw. */
class RecordingConsumer implements OutboxConsumer
{
    /** @var list<array{id: string, ctx: ?string}> */
    public array $received = [];

    public bool $fail = false;

    public function __construct(private readonly string $name = 'test.recorder') {}

    public function name(): string
    {
        return $this->name;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return true;
    }

    public function handle(OutboxEnvelope $event): void
    {
        if ($this->fail) {
            throw new \RuntimeException('consumer failed');
        }

        $this->received[] = [
            'id' => $event->eventId,
            'ctx' => DB::selectOne("select nullif(current_setting('app.workspace_id', true), '') as ctx")->ctx,
        ];
    }
}
