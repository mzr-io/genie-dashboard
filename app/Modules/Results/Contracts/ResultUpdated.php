<?php

namespace App\Modules\Results\Contracts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * What a browser hears when a Block's result changes (Story 4.13; AD-16): the Block and the new ETag, nothing else. A client that holds another ETag
 * asks `/api/v1` for that item; no value, name or user travels on the socket. Broadcast on the private channel of the Block (shared data) or of the
 * member (their own data): `workspace.{ws}.block.{block}` or `workspace.{ws}.membership.{membership}`.
 */
final class ResultUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $blockId,
        public readonly string $resultEtag,
        public readonly ?string $membershipId = null,
    ) {}

    /** The channel name without the `private-` prefix the protocol adds. */
    public static function blockChannel(string $workspaceId, string $blockId): string
    {
        return 'workspace.'.strtolower($workspaceId).'.block.'.strtolower($blockId);
    }

    public static function membershipChannel(string $workspaceId, string $membershipId): string
    {
        return 'workspace.'.strtolower($workspaceId).'.membership.'.strtolower($membershipId);
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(
            $this->membershipId === null
                ? self::blockChannel($this->workspaceId, $this->blockId)
                : self::membershipChannel($this->workspaceId, $this->membershipId),
        )];
    }

    public function broadcastAs(): string
    {
        return 'result.updated';
    }

    /** @return array{block_id: string, result_etag: string} */
    public function broadcastWith(): array
    {
        return ['block_id' => strtolower($this->blockId), 'result_etag' => $this->resultEtag];
    }
}
