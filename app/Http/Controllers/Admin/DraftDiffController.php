<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Blocks\Contracts\BlockVersionDiff;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What a Draft changed against the published version (Story 6.3): read-only, `blocks.edit`, no lock claim. A Draft of another Workspace or a discarded
 * one is a 404.
 */
final class DraftDiffController extends Controller
{
    public function __construct(private readonly BlockVersionDiff $diff) {}

    public function show(Request $request, string $draft): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        abort_unless(Str::isUuid($draft), 404);

        try {
            $result = $this->diff->forDraft($workspaceId, strtolower($draft));
        } catch (DraftNotFound) {
            abort(404);
        }

        return response()->json(['data' => $result], 200, ['Cache-Control' => 'no-store, private']);
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
