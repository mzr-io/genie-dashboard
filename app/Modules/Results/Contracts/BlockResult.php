<?php

namespace App\Modules\Results\Contracts;

/**
 * The precomputed display data of one Block Version in one compute context (Story 4.2; AD-25). `renderPayload` is locale-free and user-free; its
 * measures are decimal strings with format descriptors, never floats. `stale` and `refreshing` are set by the read path (Freshness, Story 4.3); a
 * computed result stores them false. `resultEtag` is `sha256(key, generation_id, v)`.
 *
 * @phpstan-type SlotState array{state: string, reason?: string}
 */
final readonly class BlockResult
{
    /** The DTO's own version: bumped when a field is added or changes meaning, and part of the ETag. */
    public const VERSION = 1;

    /**
     * @param  array<string, array{state: string, reason?: string}>  $slotStates  slot key => `ok`, `empty` or `unavailable` (with its reason)
     * @param  array<string, mixed>  $renderPayload
     */
    public function __construct(
        public int $v,
        public string $generationId,
        public int $payloadSeq,
        public ?string $dataAsOf,
        public ?string $lastSuccessAt,
        public ResultState $state,
        public bool $stale,
        public bool $refreshing,
        public array $slotStates,
        public array $renderPayload,
        public string $resultEtag,
        public string $computedAt,
    ) {}

    public static function etag(string $key, string $generationId, int $v = self::VERSION): string
    {
        return hash('sha256', $key."\n".$generationId."\n".$v);
    }

    /** The same result judged by the read path (Freshness): the flags are the only fields it changes. */
    public function withFreshness(bool $stale, bool $refreshing): self
    {
        return new self($this->v, $this->generationId, $this->payloadSeq, $this->dataAsOf, $this->lastSuccessAt, $this->state, $stale, $refreshing, $this->slotStates, $this->renderPayload, $this->resultEtag, $this->computedAt);
    }

    /** Some slots are unavailable while others hold a value: derived, never stored. */
    public function partial(): bool
    {
        $states = array_column($this->slotStates, 'state');

        return in_array('unavailable', $states, true) && in_array('ok', $states, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'generation_id' => $this->generationId,
            'payload_seq' => $this->payloadSeq,
            'data_as_of' => $this->dataAsOf,
            'last_success_at' => $this->lastSuccessAt,
            'state' => $this->state->value,
            'stale' => $this->stale,
            'refreshing' => $this->refreshing,
            'slot_states' => $this->slotStates,
            'render_payload' => $this->renderPayload,
            'result_etag' => $this->resultEtag,
            'computed_at' => $this->computedAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $state = is_string($data['state'] ?? null) ? ResultState::tryFrom($data['state']) : null;

        if (($data['v'] ?? null) !== self::VERSION || $state === null || ! is_string($data['generation_id'] ?? null) || ! is_int($data['payload_seq'] ?? null)
            || ! is_array($data['slot_states'] ?? null) || ! is_array($data['render_payload'] ?? null) || ! is_string($data['result_etag'] ?? null) || ! is_string($data['computed_at'] ?? null)) {
            return null;
        }

        return new self(
            self::VERSION, $data['generation_id'], $data['payload_seq'],
            is_string($data['data_as_of'] ?? null) ? $data['data_as_of'] : null,
            is_string($data['last_success_at'] ?? null) ? $data['last_success_at'] : null,
            $state, ($data['stale'] ?? false) === true, ($data['refreshing'] ?? false) === true,
            $data['slot_states'], $data['render_payload'], $data['result_etag'], $data['computed_at'],
        );
    }
}
