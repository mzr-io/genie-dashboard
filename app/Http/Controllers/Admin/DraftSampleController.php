<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftSampleRequest;
use App\Http\Resources\DraftResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSampleFetches;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\ErrorCode;
use App\Modules\Blocks\Contracts\SampleCheck;
use App\Modules\Blocks\Contracts\SampleRejected;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\StoredSample;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The sample of a Draft (Story 3.8, the paste; Story 3.9, the fetch). Every route sits behind the `admin` middleware (`blocks.edit`); the Workspace is the session's. A
 * Draft of another Workspace, or a discarded one, is a 404. Checking a text stores nothing; reading is allowed to a read-only viewer; storing
 * needs the edit-lock claim of a Draft save (a stale epoch or a token that does not hold the lock is a 423, nothing written). The text, and
 * anything in it, is never written to a log, the audit trail or an error body: an error carries the line and column of a fault and what is
 * wrong there, never the text.
 */
final class DraftSampleController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DraftSamples $samples,
        private readonly DraftSampleFetches $fetches,
        private readonly Drafts $drafts,
        private readonly MembershipLookup $memberships,
        private readonly EditLock $lock,
    ) {}

    /** Reads a pasted text and says whether it is acceptable. Nothing is stored. */
    public function check(DraftSampleRequest $request, string $draft): JsonResponse
    {
        $this->ensureDraft($request, $draft);

        return response()->json(['data' => self::checkBody($this->samples->check($request->text()), false)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** The Draft's stored sample for the wizard to restore, or `data: null` when it has none (or it has ended). */
    public function show(Request $request, string $draft): JsonResponse
    {
        try {
            $found = $this->samples->find($this->workspaceId($request), $draft);
        } catch (DraftNotFound) {
            abort(404);
        } catch (SampleUnavailable) {
            return AdminApiError::json($request, ErrorCode::SampleUnavailable->value, 503, 'The sample cannot be opened right now.');
        }

        return response()->json(['data' => $found === null ? null : self::body($found)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function store(DraftSampleRequest $request, string $draft): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $stored = $this->samples->store($actor, $draft, $request->text(), $request->containsPersonalData(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (SampleRejected $e) {
            return AdminApiError::json($request, ErrorCode::SampleInvalid->value, 422, 'This sample was not stored.', extra: ['reason' => $e->reason] + ($e->check === null ? [] : ['check' => self::checkBody($e->check, false)]));
        } catch (SampleUnavailable) {
            return AdminApiError::json($request, ErrorCode::SampleUnavailable->value, 503, 'The sample cannot be stored right now.');
        }

        return response()->json(['data' => self::body($stored)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /**
     * Fetch from API (Story 3.9): starts the `draft_sample_fetch` Operation (`202` with its id, to poll at `GET /api/v1/operations/{id}`) and calls
     * nothing from this tier. The Draft needs the edit-lock claim of a save (423) and the personal-data acknowledgement; a setting that is unset is a
     * 422 `blocks.sample_invalid` naming it; a value that does not fit the Endpoint is a 422 `values.{name}`; over the rate limit is a 429 with
     * `retry_after`. The sample is read back with {@see self::show()} once the Operation has succeeded.
     */
    public function fetch(DraftSampleRequest $request, string $draft): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $operation = $this->fetches->start(
                $actor, $draft, $request->dataSourceId(), $request->endpointId(), $request->bindings(), $request->range(), $request->period(),
                $request->requestKey(), $request->containsPersonalData(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft),
            );
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (SampleRejected $e) {
            return AdminApiError::json(
                $request, ErrorCode::SampleInvalid->value, 422, 'The sample was not fetched.',
                extra: ['reason' => $e->reason] + ($e->setting === null ? [] : ['setting' => $e->setting]) + ($e->check === null ? [] : ['check' => self::checkBody($e->check, false)]),
            );
        } catch (InvalidDataSource $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: $e->errors, extra: $e->reasons === [] ? [] : ['reasons' => $e->reasons]);
        } catch (SampleFetchThrottled $e) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many fetches.', extra: ['reason' => 'sample-fetch-throttled'], errorExtra: ['retry_after' => $e->retryAfter])
                ->header('Retry-After', (string) $e->retryAfter);
        }

        return response()->json(
            ['data' => ['operation_id' => $operation->id, 'status' => $operation->status->value, 'expires_at' => $operation->expiresAt, 'draft_revision' => $operation->subjectRevision]],
            202,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    /** @return array<string, mixed> */
    private static function body(StoredSample $sample): array
    {
        return [
            'origin' => $sample->origin,
            'text' => $sample->text,
            'size_bytes' => $sample->sizeBytes,
            'pasted_at' => $sample->pastedAt,
            'expires_at' => $sample->expiresAt,
            'contains_personal_data' => $sample->containsPersonalData,
            'sample_revision' => $sample->sampleRevision,
            'request_key' => $sample->requestKey,
            'fetch_status' => $sample->fetchStatus,
            'fetch_latency_ms' => $sample->fetchLatencyMs,
        ] + self::checkBody($sample->check, true);
    }

    /** @return array<string, mixed> */
    private static function checkBody(SampleCheck $check, bool $full): array
    {
        $shape = $check->shape;

        return [
            'valid' => $check->valid,
            'size_bytes' => $check->sizeBytes,
            'reason' => $check->reason,
            'line' => $check->line,
            'column' => $check->column,
            'problem' => $check->problem,
            'limit' => $check->limit,
            'setting' => $check->setting,
            'shape' => $shape === null ? null : [
                'records' => $shape['records'],
                'scalars' => $shape['scalars'],
                'arrays' => $shape['arrays'],
                'objects' => $shape['objects'],
            ] + ($full ? ['nodes' => $shape['nodes'], 'tables' => (object) $shape['tables']] : []),
        ];
    }

    private function ensureDraft(Request $request, string $draft): void
    {
        try {
            $this->drafts->find($this->workspaceId($request), $draft);
        } catch (DraftNotFound) {
            abort(404);
        }
    }

    /**
     * The check that the request's lock token still holds the soft lock, as on a Draft save: with the lock enabled a missing token is a
     * lost lock. Null when the lock is off (the epoch check alone applies).
     *
     * @return (\Closure(): bool)|null
     */
    private function holdsLock(DraftSampleRequest $request, DraftActor $actor, string $draft): ?\Closure
    {
        if (! $this->lock->enabled()) {
            return null;
        }

        $token = $request->lockToken();
        $epoch = $request->lockEpoch();

        return fn (): bool => $token !== '' && $this->lock->holds($actor->workspaceId, Drafts::LOCK_TYPE, $draft, $actor->membershipId, $token, $epoch);
    }

    private function actor(Request $request): DraftActor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new DraftActor($membership->membershipId, $membership->workspaceId);
            }
        }

        abort(404);
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
