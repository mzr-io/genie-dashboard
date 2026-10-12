<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMembership;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Access\Contracts\SubjectAccess;
use App\Modules\Blocks\Contracts\BlockCatalogue;
use App\Modules\Blocks\Contracts\BlockSamples;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Add-blocks Panel's list (Story 4.7): the Blocks the member may add. Every published Block the member can see, newest first; nothing of a source
 * but the Data Source's name. Group access (Epic 6) only ever narrows this list through {@see SubjectAccess}.
 */
final class BlockCatalogueController extends Controller
{
    use ResolvesMembership;

    public function __construct(
        private readonly BlockCatalogue $catalogue,
        private readonly SubjectAccess $access,
        private readonly MembershipLookup $memberships,
        private readonly BlockSamples $samples,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        $blocks = $this->catalogue->published($workspaceId);
        $visible = $this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, array_map(fn ($b) => $b->blockId, $blocks));
        $shown = array_values(array_filter($blocks, fn ($b) => $visible[$b->blockId] ?? false));

        return response()->json([
            'data' => array_map(fn ($b) => $b->toArray(), $shown),
            'meta' => ['total' => count($shown)],
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * The panel's inline preview (Story 4.8): the Block's render payload with generated sample values. Nothing here reads a source, a payload or a
     * result, and nothing is stored or subscribed; the same version gives the same values. A Block the member cannot see is a 404.
     */
    public function sample(Request $request, string $block): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $id = strtolower($block);

        abort_unless($this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, [$id])[$id] ?? false, 404);

        $sample = $this->samples->forBlock($workspaceId, $id);

        abort_if($sample === null, 404);

        return response()->json(['data' => $sample + ['block_id' => $id, 'sample' => true]], 200, ['Cache-Control' => 'no-store, private']);
    }
}
