<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\BulkActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk destroy, shared by every list that offers it.
 *
 * Kept out of the individual controllers because the interesting part is not
 * the deletion - it is the refusal. A list of a hundred products where seven
 * carry stock must not be wiped because ninety-three were safe, and the person
 * who asked needs to be told exactly which seven and why, rather than getting a
 * generic failure and no idea what survived.
 */
class BulkDestroyController extends Controller
{
    public function __construct(protected BulkActionService $bulk) {}

    /**
     * Delete many rows of one model.
     *
     * POST rather than DELETE because the ids travel in the body, and a DELETE
     * with a payload is dropped by some proxies. The verb still carries the
     * intent in the route name.
     */
    public function __invoke(Request $request, string $resource): JsonResponse
    {
        $handler = $this->bulk->handlerFor($resource);

        if ($handler === null) {
            abort(404);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $result = $handler($validated['ids'], $request->user());

        // 207 when the request was partly honoured. The rows that were deleted
        // really were deleted, and reporting that as a plain failure would
        // invite the user to press the button again - which is how a second
        // pass deletes things the first pass deliberately spared.
        return response()->json($result, $result['refused'] === [] ? 200 : 207);
    }
}
