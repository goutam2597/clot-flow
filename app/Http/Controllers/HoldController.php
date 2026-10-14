<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\HeldSale;
use App\Models\Product;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Held (parked) sales for the point of sale.
 *
 * Separate from PosController because this is its own lifecycle: a hold is
 * created, listed, resumed and released, and none of those are "checkout".
 * Keeping it apart also keeps the parking path free of the pricing and stock
 * machinery that a real sale needs — parking must never be able to deduct
 * stock or post a journal entry, even by accident.
 */
class HoldController extends Controller
{
    public function __construct(protected HoldService $holds) {}

    /** Holds waiting on this branch. */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);

        return response()->json([
            'holds' => $this->holds->listLive($branchId),
        ]);
    }

    /**
     * Park the current cart.
     *
     * The client sends its own cart shape; nothing is re-derived here because
     * parking is a snapshot, not a transaction. The service does validate that
     * every line refers to a product that still exists and is active, so an
     * unresumable hold is never created.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'lines' => __('cart lines'),
            'lines.*.product_id' => __('product'),
        ]);

        try {
            $hold = $this->holds->park($validator, [
                'branch_id' => $this->branchId($request),
                'warehouse_id' => $request->integer('warehouse_id') ?: null,
                'cash_register_session_id' => $request->integer('cash_register_session_id') ?: null,
                'user_id' => $user->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'hold' => [
                'reference' => $hold->reference,
                'label' => $hold->label,
                'lines' => $hold->line_count,
                'total' => $hold->cached_total,
            ],
            'holds' => $this->holds->listLive($hold->branch_id),
        ], 201);
    }

    /** Put a held cart back on the till, re-priced. */
    public function resume(Request $request, HeldSale $heldSale): JsonResponse
    {
        $this->authorizeBranch($request, $heldSale);

        try {
            $cart = $this->holds->resume($heldSale);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'cart' => $cart,
        ]);
    }

    /** Discard a hold. Kept for the audit trail, not hard-deleted. */
    public function destroy(Request $request, HeldSale $heldSale): JsonResponse
    {
        $this->authorizeBranch($request, $heldSale);

        $this->holds->release($heldSale);

        return response()->json([
            'success' => true,
            'holds' => $this->holds->listLive($heldSale->branch_id),
        ]);
    }

    /* ------------------------------------------------------------------ */

    protected function branchId(Request $request): ?int
    {
        $user = $request->user();

        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return Branch::where('is_active', true)->where('is_default', true)->value('id')
            ?? Branch::where('is_active', true)->orderBy('id')->value('id');
    }

    /**
     * A hold belongs to the branch that parked it.
     *
     * Checked here rather than relying on a scope because a resume is a write:
     * returning a 404 rather than a 403 means a cashier on one branch cannot
     * learn that a hold with a given reference exists on another.
     */
    protected function authorizeBranch(Request $request, HeldSale $hold): void
    {
        $branchId = $this->branchId($request);

        abort_unless(
            $hold->branch_id === null || $branchId === null || (int) $hold->branch_id === (int) $branchId,
            404
        );
    }
}