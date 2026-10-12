<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use Illuminate\Http\JsonResponse;

/**
 * `GET api/v1/block-types` (Story 3.1): the registered Block Types with their slots, size limits and default aggregations. It sits behind the
 * `admin` middleware with `blocks.edit` (mapped in `ShellNavigation::ADMIN_API_ROUTES`), so a User-area session gets the 403 and its audit.
 */
final class BlockTypeController extends Controller
{
    public function __construct(private readonly BlockTypeRegistry $registry) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (BlockType $type): array => $type->toArray(), $this->registry->all()),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
