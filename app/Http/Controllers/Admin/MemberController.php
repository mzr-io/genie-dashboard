<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListMembersRequest;
use App\Http\Resources\MemberResource;
use App\Modules\Access\Contracts\InvalidMemberCursor;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Workspace's members and pending invitations (Story 1.20). Both routes sit behind the `admin` middleware
 * (`users.manage`); the Workspace is the session's, never a client-supplied ID, and another Workspace's
 * membership is invisible (404) under row-level security.
 */
final class MemberController extends Controller
{
    /** Member data is personal and per-Workspace: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    public function __construct(private readonly MemberDirectory $members) {}

    public function index(ListMembersRequest $request): JsonResponse
    {
        $query = $request->memberQuery();

        try {
            $page = $this->members->page($this->workspaceId($request), $query);
        } catch (InvalidMemberCursor) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return MemberResource::collection($page->rows)->additional(['meta' => [
            'per_page' => $query->pageSize,
            'next_cursor' => $page->nextCursor,
            'total' => $page->total,
            'matched' => $page->matched,
            'sort' => $query->sort->value,
            'direction' => $query->descending ? 'desc' : 'asc',
        ]])->response()->header('Cache-Control', self::NO_STORE);
    }

    public function show(Request $request, string $membership): JsonResponse
    {
        $row = $this->members->find($this->workspaceId($request), $membership);

        abort_if($row === null, 404);

        return (new MemberResource($row))->response()->header('Cache-Control', self::NO_STORE);
    }

    private function workspaceId(Request $request): string
    {
        // The admin middleware has already proven an active Admin membership in this session Workspace.
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
