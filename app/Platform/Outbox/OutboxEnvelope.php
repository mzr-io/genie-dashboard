<?php

namespace App\Platform\Outbox;

use Carbon\CarbonImmutable;

/**
 * The outbox envelope: `{event_id, type, v, workspace_id, subject, subject_seq, occurred_at, actor,
 * request_id, data}`. `data` holds IDs, enums, booleans and integers only.
 */
final readonly class OutboxEnvelope
{
    /**
     * @param  array<string, int|bool|string|null>  $data
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public int $v,
        public string $workspaceId,
        public string $subject,
        public int $subjectSeq,
        public CarbonImmutable $occurredAt,
        public ?string $actor,
        public ?string $requestId,
        public array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $row  a row of `outbox_events` (database column names)
     */
    public static function fromRow(array $row): self
    {
        $data = $row['data'] ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        }

        return new self(
            (string) $row['id'],
            (string) $row['type'],
            (int) $row['v'],
            (string) $row['workspace_id'],
            (string) $row['subject'],
            (int) $row['subject_seq'],
            CarbonImmutable::parse((string) $row['occurred_at']),
            $row['actor'] === null ? null : (string) $row['actor'],
            $row['request_id'] === null ? null : (string) $row['request_id'],
            is_array($data) ? $data : [],
        );
    }

    /**
     * @return array{event_id: string, type: string, v: int, workspace_id: string, subject: string, subject_seq: int, occurred_at: string, actor: ?string, request_id: ?string, data: array<string, int|bool|string|null>}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'v' => $this->v,
            'workspace_id' => $this->workspaceId,
            'subject' => $this->subject,
            'subject_seq' => $this->subjectSeq,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'actor' => $this->actor,
            'request_id' => $this->requestId,
            'data' => $this->data,
        ];
    }
}
