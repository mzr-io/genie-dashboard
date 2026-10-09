<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
use App\Platform\EditLock\EditLockBusy;
use App\Platform\EditLock\EditLockResourceMissing;
use App\Platform\EditLock\LockResult;
use App\Platform\EditLock\LockStatus;
use App\Platform\Tenancy\WorkspaceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The soft lock on the Data source form (Story 2.8): acquire, heartbeat, release, take over and flush. Every route sits behind
 * the `admin` middleware (`data_sources.manage`), and every call looks the Data Source up first under row-level security, so
 * one of another Workspace is a 404: a lock never grants access. The Workspace is the session's. None of these routes takes a
 * secret value: only the lock token (and, for a beacon, the CSRF token) is read from the body, and nothing else.
 *
 * A call answers 200 with `{data: {enabled, status, ...}}`; `status` is one of the {@see LockStatus} values. The holder's lock
 * token and the CSRF token (for the unload beacon) are only ever sent to the caller that holds, or is taking over, the lock.
 */
final class DataSourceLockController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly EditLock $lock,
        private readonly DataSources $sources,
        private readonly MembershipLookup $memberships,
    ) {}

    /** Takes the lock when it is free (or already the caller's); otherwise says who holds it. `{enabled: false}` when the soft lock is off. */
    public function acquire(Request $request, string $dataSource): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->caller($request, $dataSource);

        return $this->answer($request, fn (): LockResult => $this->lock->acquire($workspaceId, DataSources::LOCK_TYPE, $dataSource, $membershipId, $this->name($request), $this->since()));
    }

    /** Renews the TTL; reports a pending flush request, or that the lock was taken over or lost. Sent with `X-Background: 1`. */
    public function heartbeat(Request $request, string $dataSource): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->caller($request, $dataSource);

        return $this->answer($request, fn (): LockResult => $this->lock->heartbeat($workspaceId, DataSources::LOCK_TYPE, $dataSource, $membershipId, $this->token($request)));
    }

    /** Frees the lock when the token holds it. The token and the CSRF token may come in the body, so `navigator.sendBeacon` works. */
    public function release(Request $request, string $dataSource): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->caller($request, $dataSource);

        try {
            $released = $this->lock->release($workspaceId, DataSources::LOCK_TYPE, $dataSource, $membershipId, $this->token($request));
        } catch (EditLockResourceMissing) {
            abort(404);
        } catch (EditLockBusy) {
            return $this->busy($request);
        }

        return response()->json(['data' => ['enabled' => $this->lock->enabled(), 'released' => $released]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** Asks the holder to flush, then answers `waiting` (with the taker's token to poll with) or `granted`. */
    public function takeover(Request $request, string $dataSource): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->caller($request, $dataSource);

        return $this->answer($request, fn (): LockResult => $this->lock->requestTakeover($workspaceId, DataSources::LOCK_TYPE, $dataSource, $membershipId, $this->name($request), $this->since()));
    }

    /** The taker's poll, with the token the request answered: `waiting`, `granted` or `held`. Sent with `X-Background: 1`. */
    public function takeoverStatus(Request $request, string $dataSource): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->caller($request, $dataSource);

        // The taker's token travels in a header, never the URL (access logs record URLs), and only a minted one is accepted.
        $token = $request->header('X-Lock-Token');

        if (! is_string($token) || ! EditLock::isToken($token)) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, 'A lock token is required.');
        }

        return $this->answer($request, fn (): LockResult => $this->lock->takeoverStatus($workspaceId, DataSources::LOCK_TYPE, $dataSource, $membershipId, $token));
    }

    /** The holder confirms its flush: the take-over completes at once and the holder is told it was taken over. */
    public function flush(Request $request, string $dataSource): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->caller($request, $dataSource);

        return $this->answer($request, fn (): LockResult => $this->lock->acknowledgeFlush($workspaceId, DataSources::LOCK_TYPE, $dataSource, $membershipId, $this->token($request)));
    }

    /**
     * @param  \Closure(): LockResult  $call
     */
    private function answer(Request $request, \Closure $call): JsonResponse
    {
        try {
            $result = $call();
        } catch (EditLockResourceMissing) {
            abort(404);
        } catch (EditLockBusy) {
            return $this->busy($request);
        }

        return response()->json(['data' => $this->body($request, $result)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** Another call held the resource for too long: transient, so a 503 the client retries. */
    private function busy(Request $request): JsonResponse
    {
        return AdminApiError::json($request, PlatformErrorCode::ServerError->value, 503, 'The edit lock is busy. Try again.')->header('Retry-After', '1');
    }

    /** @return array<string, mixed> */
    private function body(Request $request, LockResult $result): array
    {
        $body = ['enabled' => $result->status !== LockStatus::Disabled, 'status' => $result->status->value];

        if ($result->status === LockStatus::Granted) {
            $body += [
                'token' => $result->token,
                'epoch' => $result->epoch,
                'ttl_seconds' => $result->ttlSeconds,
                'flush_requested' => $result->flushRequested,
                // For the unload beacon, which cannot send a header: the session's own CSRF token, to this caller only.
                'csrf_token' => $request->session()->token(),
            ];
        } elseif ($result->status === LockStatus::Waiting) {
            $body += ['token' => $result->token];
        } elseif ($result->status === LockStatus::Held) {
            $body += ['holder' => ['name' => $result->holderName, 'since' => $result->holderSince]];
        } elseif ($result->status === LockStatus::TakenOver) {
            $body += ['flush_acknowledged' => $result->flushAcknowledged, 'taken_over_by' => ['name' => $result->holderName, 'at' => $result->holderSince]];
        }

        return $body;
    }

    /** The lock token in the body (or JSON); anything else in the body is ignored. */
    private function token(Request $request): string
    {
        $value = $request->input('token');

        return is_string($value) && strlen($value) <= 128 ? $value : '';
    }

    private function name(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User ? mb_substr($user->name, 0, 128) : '';
    }

    private function since(): string
    {
        return CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The session's Workspace and the Admin's own active membership in it; a Data Source that is not visible there is a 404.
     *
     * @return array{0: string, 1: string}
     */
    private function caller(Request $request, string $dataSource): array
    {
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);
        $workspaceId = is_string($workspaceId) ? strtolower($workspaceId) : abort(404);
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        try {
            $this->sources->find($workspaceId, $dataSource);
        } catch (DataSourceNotFound) {
            abort(404);
        }

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === $workspaceId && $membership->status === 'active') {
                return [$workspaceId, $membership->membershipId];
            }
        }

        abort(404);
    }
}
