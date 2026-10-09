<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Platform\Operations\Operations;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /api/v1/operations/{operation}` (Story 2.5): the status and small summary of an Operation, for the membership that
 * started it. Anyone else (another member, another Workspace, an unknown or malformed ID) gets a bare 404 with no body, so
 * an Operation's existence is not even disclosed. The summary holds no body, secret, ciphertext or URL query.
 */
final class OperationController extends Controller
{
    public function __construct(
        private readonly Operations $operations,
        private readonly MembershipLookup $memberships,
    ) {}

    public function show(Request $request, string $operation): JsonResponse|Response
    {
        $user = $request->user();
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! $user instanceof User || ! is_string($workspaceId)) {
            return response()->noContent(404);
        }

        $membershipId = null;

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                $membershipId = $membership->membershipId;
            }
        }

        $found = $membershipId === null ? null : $this->operations->status(strtolower($workspaceId), $operation, $membershipId);

        if ($found === null) {
            return response()->noContent(404);
        }

        return response()->json(
            ['data' => [
                'id' => $found->id,
                'kind' => $found->kind,
                'status' => $found->status->value,
                'result' => $found->result,
                'expires_at' => $found->expiresAt,
            ]],
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }
}
