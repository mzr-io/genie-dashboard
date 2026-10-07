<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DataSourceRequest;
use App\Http\Requests\Admin\ListDataSourcesRequest;
use App\Http\Resources\DataSourceResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ConfirmationThrottled;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Connector\Application\ValidateDataSourceInput;
use App\Modules\Connector\Contracts\ConfirmationRefused;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSourcePage;
use App\Modules\Connector\Contracts\DataSourceQuery;
use App\Modules\Connector\Contracts\DataSourceRevisionConflict;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SecretsNotConfigured;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Data Sources (Story 2.3). Every route sits behind the `admin` middleware (`data_sources.manage`); the Workspace is
 * the session's, never a client-supplied ID. A Data Source of another Workspace is invisible (404, under row-level
 * security). A stale `revision` is a 409 with the current state. Nothing here contacts any host.
 *
 * Credentials (Story 2.4) are write-only: a response carries each secret slot as `{configured, updated_at}` only. Setting,
 * replacing or removing one, or changing the auth type, needs `confirm_password` (the rules, throttle key and error shapes of
 * the member editor: wrong 422, throttled 429); an unset platform key is a 503 and nothing is stored.
 */
final class DataSourceController extends Controller
{
    /** Data sources are Admin-only configuration: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    private const CONFIRM_ATTEMPTS = 6;

    private const CONFIRM_DECAY_SECONDS = 60;

    public function __construct(
        private readonly DataSources $sources,
        private readonly ValidateDataSourceInput $validator,
        private readonly MembershipLookup $memberships,
    ) {}

    public function index(ListDataSourcesRequest $request): JsonResponse
    {
        $query = $request->dataSourceQuery();
        $page = $this->sources->list($this->workspaceId($request), $query);

        return response()->json($this->body($request, $page, $query), 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function show(Request $request, string $dataSource): JsonResponse
    {
        try {
            $found = $this->sources->find($this->workspaceId($request), $dataSource);
        } catch (DataSourceNotFound) {
            abort(404);
        }

        return $this->one($request, $found, 200);
    }

    public function store(DataSourceRequest $request): JsonResponse
    {
        try {
            $created = $this->sources->register($this->actor($request), $request->dataSourceInput(), fn (): bool => $this->confirmPassword($request));
        } catch (InvalidDataSource $e) {
            return $this->invalid($request, $e);
        } catch (ConfirmationRefused|ConfirmationThrottled|SecretsNotConfigured $e) {
            return $this->refused($request, $e);
        }

        return $this->one($request, $created, 201);
    }

    public function update(DataSourceRequest $request, string $dataSource): JsonResponse
    {
        try {
            $updated = $this->sources->update($this->actor($request), $dataSource, $request->dataSourceInput(), $request->revision(), fn (): bool => $this->confirmPassword($request));
        } catch (DataSourceNotFound) {
            abort(404);
        } catch (DataSourceRevisionConflict $e) {
            return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'This data source was changed by someone else.', extra: [
                'current' => ['data' => (new DataSourceResource($e->current))->resolve($request), 'meta' => $this->meta()],
            ]);
        } catch (InvalidDataSource $e) {
            return $this->invalid($request, $e);
        } catch (ConfirmationRefused|ConfirmationThrottled|SecretsNotConfigured $e) {
            return $this->refused($request, $e);
        }

        return $this->one($request, $updated, 200);
    }

    /**
     * The blur check on the Base URL: the allowlist and `require_https` only. Nothing is resolved, requested or written.
     */
    public function checkUrl(Request $request): JsonResponse
    {
        try {
            $url = $this->validator->url($request->input('base_url'));
            $this->sources->checkUrl($this->workspaceId($request), $url ?? throw new \LogicException('The base URL was not parsed.'));
        } catch (InvalidDataSource $e) {
            return $this->invalid($request, $e);
        }

        return response()->json(['data' => ['allowed' => true]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** A wrong password is a 422 on `confirm_password`, too many wrong ones a 429, an unset platform key a 503. */
    private function refused(Request $request, ConfirmationRefused|ConfirmationThrottled|SecretsNotConfigured $e): JsonResponse
    {
        return match (true) {
            $e instanceof ConfirmationThrottled => AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many attempts.', ['confirm_password' => ['Too many attempts.']])
                ->header('Retry-After', (string) $e->retryAfter),
            $e instanceof SecretsNotConfigured => AdminApiError::json($request, ErrorCode::SecretsNotConfigured->value, 503, 'Credentials cannot be saved until the platform key is configured.', extra: ['reason' => 'secrets-not-configured']),
            default => AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: ['confirm_password' => ['The password is incorrect.']]),
        };
    }

    /** True when the password is right. Wrong guesses are throttled per person and IP; a right guess never clears them. */
    private function confirmPassword(DataSourceRequest $request): bool
    {
        $user = $request->user();
        $key = 'member-confirm:'.($user instanceof User ? $user->id : '').'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CONFIRM_ATTEMPTS)) {
            throw new ConfirmationThrottled(RateLimiter::availableIn($key));
        }

        $given = $request->confirmation();

        // A missing password is a field error, not a guess: it does not count against the throttle.
        if ($given === '') {
            return false;
        }

        if (! $user instanceof User || ! Hash::check($given, $user->getAuthPassword())) {
            RateLimiter::hit($key, self::CONFIRM_DECAY_SECONDS);

            return false;
        }

        return true;
    }

    private function invalid(Request $request, InvalidDataSource $e): JsonResponse
    {
        return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: $e->errors, extra: $e->reasons === [] ? [] : ['reasons' => $e->reasons]);
    }

    private function one(Request $request, DataSource $source, int $status): JsonResponse
    {
        return response()->json(
            ['data' => (new DataSourceResource($source))->resolve($request), 'meta' => $this->meta()],
            $status,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    /**
     * The platform ceilings the form shows beside the limits (null: not set, nothing is checked).
     *
     * @return array{ceilings: array<string, int|null>}
     */
    private function meta(): array
    {
        $ceilings = $this->sources->ceilings();

        return ['ceilings' => [
            'timeout_seconds' => $ceilings->timeoutSeconds,
            'max_response_bytes' => $ceilings->maxResponseBytes,
            'max_pages' => $ceilings->maxPages,
        ]];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function body(Request $request, DataSourcePage $page, DataSourceQuery $query): array
    {
        return [
            'data' => array_map(fn (DataSource $row): array => (new DataSourceResource($row, false))->resolve($request), $page->rows),
            'meta' => [
                'total' => $page->total,
                'matched' => $page->matched,
                'sort' => $query->sort->value,
                'direction' => $query->descending ? 'desc' : 'asc',
            ] + $this->meta(),
        ];
    }

    /** The Admin's own active membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
    private function actor(Request $request): DataSourceActor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new DataSourceActor($membership->membershipId, $membership->workspaceId);
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
