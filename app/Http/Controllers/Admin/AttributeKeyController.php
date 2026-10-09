<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttributeKeyRequest;
use App\Http\Requests\Admin\ListAttributeKeysRequest;
use App\Http\Resources\AttributeKeyResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\AttributeKeyNotFound;
use App\Modules\Access\Contracts\AttributeKeyRevisionConflict;
use App\Modules\Access\Contracts\AttributeKeys;
use App\Modules\Access\Contracts\AttributeKeyTaken;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Workspace's user attribute keys (Story 2.12). Every route sits behind the `admin` middleware (`settings.manage`);
 * the Workspace is the session's, never a client-supplied ID. The key id and the value type are fixed at creation: a
 * rename takes only the label and the revision seen (a stale one is a 409 with the key as it stands). There is no delete.
 */
final class AttributeKeyController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly AttributeKeys $keys,
        private readonly MembershipLookup $memberships,
    ) {}

    public function index(ListAttributeKeysRequest $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $rows = $this->keys->list($workspaceId, $request->search());

        return response()->json([
            'data' => array_map(fn ($row): array => (new AttributeKeyResource($row))->resolve($request), $rows),
            'meta' => ['total' => $this->keys->count($workspaceId), 'matched' => count($rows)],
        ], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function store(AttributeKeyRequest $request): JsonResponse
    {
        try {
            $row = $this->keys->create($this->editor($request), $request->keyId(), $request->label(), $request->valueType());
        } catch (AttributeKeyTaken $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: [$e->field => [$e->field === 'label' ? 'This label is already used.' : 'This key id is already used.']], extra: ['reasons' => [$e->field => 'duplicate']]);
        }

        return response()->json(['data' => (new AttributeKeyResource($row))->resolve($request)], 201, ['Cache-Control' => self::NO_STORE]);
    }

    public function update(AttributeKeyRequest $request, string $key): JsonResponse
    {
        try {
            $row = $this->keys->rename($this->editor($request), $key, $request->label(), $request->revision());
        } catch (AttributeKeyNotFound) {
            abort(404);
        } catch (AttributeKeyTaken $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: [$e->field => ['This label is already used.']], extra: ['reasons' => [$e->field => 'duplicate']]);
        } catch (AttributeKeyRevisionConflict $e) {
            return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'This attribute was changed by someone else.', extra: [
                'current' => (new AttributeKeyResource($e->current))->resolve($request),
            ]);
        }

        return response()->json(['data' => (new AttributeKeyResource($row))->resolve($request)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    private function editor(Request $request): MemberEditor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new MemberEditor($user->id, $membership->membershipId, $membership->workspaceId);
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
