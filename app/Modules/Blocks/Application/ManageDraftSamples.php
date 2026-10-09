<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\SampleCheck;
use App\Modules\Blocks\Contracts\SampleRejected;
use App\Modules\Blocks\Contracts\SampleVault;
use App\Modules\Blocks\Contracts\StoredSample;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LimitSetting;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pasted and fetched samples of Drafts (Stories 3.8, 3.9), in the Workspace's transaction under row-level security.
 *
 * {@see self::check()} applies the platform's two `pending_input` limits (the byte ceiling `tunables.guards.max_bytes` and the depth
 * limit `tunables.guards.depth_limit`, the same ones Story 2.6 applies to a fetch) and reads the text with {@see LosslessJson}, which keeps
 * every number as the lexeme received. A limit that is unset, or set to something that is not a positive whole number, is not guessed: the
 * paste is refused and the reason names the setting. This module never calls PHP's own decoder.
 *
 * {@see self::store()} takes the Draft's row lock, compares the edit-lock epoch and claim as a save does, then requires the personal-data
 * acknowledgement and a `tunables.timeouts.draft_sample_ttl` (whole seconds, unset means a sample cannot be stored), seals the text under the
 * workspace `data` key and writes one row per Draft (a new paste replaces it in place). The text is never logged, audited or put in an error.
 */
final class ManageDraftSamples implements DraftSamples
{
    /** The longest sample lifetime accepted: 366 days. A setting above it is treated as not configured. */
    private const MAX_TTL_SECONDS = 31_622_400;

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Repository $config,
        private readonly SampleVault $vault,
        private readonly Drafts $drafts,
    ) {}

    public function check(string $text): SampleCheck
    {
        $size = strlen($text);

        try {
            $maxBytes = LimitSetting::positive('max_bytes', $this->config->get('dashflow.tunables.guards.max_bytes.value'));
        } catch (InvalidLimitSetting) {
            $maxBytes = null;
        }

        if ($maxBytes === null) {
            return new SampleCheck(false, $size, SampleCheck::LIMIT_NOT_CONFIGURED, setting: 'max_bytes');
        }

        try {
            $maxDepth = LimitSetting::positive('depth_limit', $this->config->get('dashflow.tunables.guards.depth_limit.value'));
        } catch (InvalidLimitSetting) {
            $maxDepth = null;
        }

        if ($maxDepth === null) {
            return new SampleCheck(false, $size, SampleCheck::LIMIT_NOT_CONFIGURED, setting: 'depth_limit');
        }

        if ($size > $maxBytes) {
            return new SampleCheck(false, $size, SampleCheck::TOO_LARGE, limit: $maxBytes);
        }

        try {
            $document = LosslessJson::decode($text, $maxDepth);
        } catch (JsonDepthExceeded) {
            return new SampleCheck(false, $size, SampleCheck::TOO_DEEP, limit: $maxDepth);
        } catch (JsonParseFailed $e) {
            [$line, $column] = self::position($text, $e->offset);

            return new SampleCheck(false, $size, SampleCheck::NOT_JSON, $line, $column, $e->reason);
        }

        return new SampleCheck(true, $size, shape: SampleShape::of($document));
    }

    public function store(DraftActor $actor, string $draftId, string $text, bool $containsPersonalData, int $lockEpoch, ?\Closure $holdsLock = null): StoredSample
    {
        return $this->write($actor, $draftId, $text, $containsPersonalData, $lockEpoch, $holdsLock, null);
    }

    public function storeFetched(DraftActor $actor, string $draftId, string $text, int $lockEpoch, int $status, int $latencyMs, string $requestKey): StoredSample
    {
        // The Admin acknowledged the personal-data notice when the fetch was started: it is required there, before anything is queued.
        return $this->write($actor, $draftId, $text, true, $lockEpoch, null, ['status' => $status, 'latency_ms' => $latencyMs, 'request_key' => $requestKey]);
    }

    /**
     * One writer for both origins: the Draft's row lock, the lock epoch (and the lock claim of a paste), the acknowledgement, the limits, the TTL, the sealing and
     * the replacement in place, which raises `sample_revision`. `$fetch` is set for a fetched sample.
     *
     * @param  array{status: int, latency_ms: int, request_key: string}|null  $fetch
     */
    private function write(DraftActor $actor, string $draftId, string $text, bool $containsPersonalData, int $lockEpoch, ?\Closure $holdsLock, ?array $fetch): StoredSample
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $text, $containsPersonalData, $lockEpoch, $holdsLock, $fetch): StoredSample {
            $row = Str::isUuid($draftId)
                ? DB::selectOne("select id, lock_epoch from block_versions where workspace_id = ? and id = ? and state = 'draft' for update", [$actor->workspaceId, strtolower($draftId)])
                : null;

            if ($row === null) {
                throw $this->notADraft($actor->workspaceId, $draftId);
            }

            $id = strtolower((string) $row->id);

            // The edit lock first, as on a save: a write by a non-holder or with a stale epoch changes nothing.
            if ((int) $row->lock_epoch !== $lockEpoch || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DraftLockLost($this->drafts->find($actor->workspaceId, $id));
            }

            if (! $containsPersonalData) {
                throw new SampleRejected(SampleRejected::ACKNOWLEDGEMENT_REQUIRED);
            }

            $check = $this->check($text);

            if (! $check->valid) {
                throw new SampleRejected((string) $check->reason, $check);
            }

            $ttl = $this->ttl() ?? throw new SampleRejected(SampleRejected::TTL_NOT_CONFIGURED);
            $sealed = $this->vault->seal($actor->workspaceId, $id, $text);
            $now = Carbon::now('UTC');
            $expires = $now->copy()->addSeconds($ttl);

            // One row per Draft: the unique index on the version is the conflict target, and a new sample takes the place of the old one.
            $stored = DB::selectOne(
                'insert into draft_samples (id, workspace_id, block_version_id, source, body, size_bytes, created_at, expires_at, contains_personal_data, created_by_membership_id, request_key, fetch_status, fetch_latency_ms) '
                .'values (?, ?, ?, ?, ?, ?, ?, ?, true, ?, ?, ?, ?) '
                .'on conflict (block_version_id) do update set source = excluded.source, body = excluded.body, size_bytes = excluded.size_bytes, '
                .'created_at = excluded.created_at, expires_at = excluded.expires_at, contains_personal_data = excluded.contains_personal_data, created_by_membership_id = excluded.created_by_membership_id, '
                .'request_key = excluded.request_key, fetch_status = excluded.fetch_status, fetch_latency_ms = excluded.fetch_latency_ms, sample_revision = draft_samples.sample_revision + 1 '
                .'returning sample_revision',
                [
                    (string) Str::uuid7(), $actor->workspaceId, $id, $fetch === null ? 'paste' : 'fetch', $sealed, strlen($text), self::stamp($now), self::stamp($expires), $actor->membershipId,
                    $fetch['request_key'] ?? null, $fetch['status'] ?? null, $fetch['latency_ms'] ?? null,
                ],
            );

            return new StoredSample(
                $text, $fetch === null ? self::ORIGIN : self::ORIGIN_FETCHED, strlen($text), $now->format('Y-m-d\TH:i:s\Z'), $expires->format('Y-m-d\TH:i:s\Z'), true, $check,
                (int) ($stored->sample_revision ?? 1), $fetch['request_key'] ?? null, $fetch['status'] ?? null, $fetch['latency_ms'] ?? null,
            );
        });
    }

    public function find(string $workspaceId, string $draftId): ?StoredSample
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $draftId): ?StoredSample {
            $draft = Str::isUuid($draftId)
                ? DB::selectOne("select id from block_versions where workspace_id = ? and id = ? and state = 'draft'", [$workspaceId, strtolower($draftId)])
                : null;

            if ($draft === null) {
                throw new DraftNotFound;
            }

            $id = strtolower((string) $draft->id);
            $row = DB::selectOne(
                "select body, source, size_bytes, contains_personal_data, sample_revision, request_key, fetch_status, fetch_latency_ms, to_char(created_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as pasted_at, "
                ."to_char(expires_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as ends_at "
                .'from draft_samples where workspace_id = ? and block_version_id = ? and expires_at > ? and body <> \'\'',
                [$workspaceId, $id, self::stamp(Carbon::now('UTC'))],
            );

            if ($row === null) {
                return null;
            }

            $text = $this->vault->open($workspaceId, $id, (string) $row->body);
            $check = $this->check($text);

            // A sample that was valid when it was stored and no longer passes (a limit was lowered) is shown as a failed check: the text is still there to read.
            return new StoredSample(
                $text, $row->source === 'fetch' ? self::ORIGIN_FETCHED : self::ORIGIN, (int) $row->size_bytes, (string) $row->pasted_at, (string) $row->ends_at, (bool) $row->contains_personal_data, $check,
                (int) $row->sample_revision, $row->request_key === null ? null : (string) $row->request_key,
                $row->fetch_status === null ? null : (int) $row->fetch_status, $row->fetch_latency_ms === null ? null : (int) $row->fetch_latency_ms,
            );
        });
    }

    /** The Draft's sample is gone from the app's view at once; the `maintenance` purge deletes the row (the `app` role holds no DELETE). */
    public static function erase(string $workspaceId, string $versionId): void
    {
        DB::update(
            "update draft_samples set body = '', size_bytes = 0, expires_at = ? where workspace_id = ? and block_version_id = ?",
            [self::stamp(Carbon::now('UTC')), $workspaceId, strtolower($versionId)],
        );
    }

    private static function stamp(Carbon $time): string
    {
        return $time->format('Y-m-d H:i:s.u').'+00';
    }

    /**
     * Line and column (1-based, in characters) of a byte offset; the first position when the fault has none.
     *
     * @return array{0: int, 1: int}
     */
    private static function position(string $text, ?int $offset): array
    {
        if ($offset === null) {
            return [1, 1];
        }

        $before = substr($text, 0, min($offset, strlen($text)));
        $line = substr_count($before, "\n") + 1;
        $start = strrpos($before, "\n");
        $tail = $start === false ? $before : substr($before, $start + 1);

        return [$line, mb_strlen($tail, 'UTF-8') + 1];
    }

    private function ttl(): ?int
    {
        try {
            $ttl = LimitSetting::positive('draft_sample_ttl', $this->config->get('dashflow.tunables.timeouts.draft_sample_ttl.value'));

            // A lifetime past the ceiling cannot be stored as a timestamp safely: it counts as not configured.
            return $ttl !== null && $ttl > self::MAX_TTL_SECONDS ? null : $ttl;
        } catch (Throwable) {
            return null;
        }
    }

    private function notADraft(string $workspaceId, string $id): Throwable
    {
        $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

        return $row !== null && $row->state !== 'discarded' ? new DraftPublished : new DraftNotFound;
    }
}
