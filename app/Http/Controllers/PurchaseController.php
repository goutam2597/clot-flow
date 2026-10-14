<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Purchase documents (supplier orders / goods receipt).
 *
 * Money and quantity maths are always recalculated server-side with bcmath;
 * stock intake and the accounts-payable journal entry are delegated to the
 * PurchaseService, so a purchase created as "received" and a purchase received
 * later behave identically.
 */
class PurchaseController extends Controller
{
    /** Document lifecycle states stored on purchases.status. */
    public const STATUSES = ['pending', 'ordered', 'received', 'partial'];

    /** Payment states stored on purchases.payment_status. */
    public const PAYMENT_STATUSES = ['due', 'partial', 'paid'];

    /** Statuses a user may pick when creating a document. */
    public const CREATABLE_STATUSES = ['pending', 'ordered', 'received'];

    public function __construct(protected PurchaseService $purchases) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->only(['search', 'status', 'payment_status', 'supplier_id', 'from', 'to']);

        $query = Purchase::query()
            ->with(['supplier', 'branch', 'warehouse'])
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('purchase_number', 'like', '%' . $term . '%')
                        ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', '%' . $term . '%'));
                });
            })
            ->when(in_array(($filters['status'] ?? null), self::STATUSES, true) ? $filters['status'] : null,
                fn ($q, $value) => $q->where('status', $value))
            ->when(in_array(($filters['payment_status'] ?? null), self::PAYMENT_STATUSES, true) ? $filters['payment_status'] : null,
                fn ($q, $value) => $q->where('payment_status', $value))
            ->when($filters['supplier_id'] ?? null, fn ($q, $value) => $q->where('supplier_id', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('purchased_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('purchased_at', '<=', $value));

        $totals = [
            'count' => (clone $query)->count(),
            'grand_total' => (string) (clone $query)->sum('grand_total'),
            'paid_total' => (string) (clone $query)->sum('paid_total'),
            'due_total' => (string) (clone $query)->sum('due_total'),
        ];

        $purchases = $query->latest('purchased_at')->paginate(20)->withQueryString();

        return view('purchases.index', [
            'purchases' => $purchases,
            'filters' => $filters,
            'totals' => $totals,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'statuses' => self::STATUSES,
            'paymentStatuses' => self::PAYMENT_STATUSES,
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();

        return view('purchases.create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)
                ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
                ->orderBy('name')
                ->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()
                ->where('status', 'active')
                ->with('taxRate')
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'cost_price', 'tax_rate_id']),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('rate')->get(),
            'statuses' => self::CREATABLE_STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'purchased_at' => ['required', 'date'],
            'status' => ['required', 'in:' . implode(',', self::CREATABLE_STATUSES)],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'paid_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'items.*.tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'items.*.product_id' => __('product'),
            'items.*.quantity' => __('quantity'),
            'items.*.unit_cost' => __('unit cost'),
            'items.*.tax_rate_id' => __('tax'),
            'items.*.discount_amount' => __('line discount'),
        ]);

        $warehouse = Warehouse::findOrFail((int) $validated['warehouse_id']);
        $branchId = $validated['branch_id'] ?? $request->user()->branch_id ?? $warehouse->branch_id;

        if (! $branchId) {
            return back()->withInput()->with('error', __('A branch is required before a purchase can be recorded.'));
        }

        $status = in_array($validated['status'], self::CREATABLE_STATUSES, true)
            ? $validated['status']
            : 'pending';

        // The service owns every monetary decision, the stock intake and the
        // payable entry, so a purchase created here behaves identically to one
        // received later.
        $payload = array_merge($validated, [
            'branch_id' => (int) $branchId,
            'warehouse_id' => (int) $validated['warehouse_id'],
            'supplier_id' => (int) $validated['supplier_id'],
            'user_id' => auth()->id(),
            'status' => $status,
            'purchased_at' => $validated['purchased_at'],
        ]);

        try {
            $purchase = $this->purchases->create($payload);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('The purchase could not be saved. Please try again.'));
        }

        AuditLogger::log(
            'purchase.created',
            Purchase::class,
            $purchase->id,
            null,
            [
                'purchase_number' => $purchase->purchase_number,
                'supplier_id' => $purchase->supplier_id,
                'warehouse_id' => $purchase->warehouse_id,
                'grand_total' => (string) $purchase->grand_total,
                'paid_total' => (string) $purchase->paid_total,
                'due_total' => (string) $purchase->due_total,
                'status' => $purchase->status,
                'payment_status' => $purchase->payment_status,
            ],
            __('Purchase :number created', ['number' => $purchase->purchase_number]),
        );

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', __('Purchase :number saved.', ['number' => $purchase->purchase_number]));
    }

    public function show(Purchase $purchase): View
    {
        $purchase->load(['items.product', 'supplier', 'branch', 'warehouse', 'user']);

        $journalEntries = JournalEntry::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->with(['lines.account', 'user'])
            ->latest('entry_date')
            ->get();

        return view('purchases.show', [
            'purchase' => $purchase,
            'journalEntries' => $journalEntries,
        ]);
    }

    /**
     * Receive a purchase: stock is increased and accounts payable is posted by
     * the PurchaseService (idempotent — a second call is a no-op).
     */
    public function receive(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_unless($request->user()->can('purchases.receive'), 403);

        if ($purchase->status === 'received') {
            return back()->with('info', __('Purchase :number has already been received.', ['number' => $purchase->purchase_number]));
        }

        try {
            $purchase = $this->purchases->receive($purchase);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('The purchase could not be received. Please try again.'));
        }

        AuditLogger::log(
            'purchase.received',
            Purchase::class,
            $purchase->id,
            null,
            [
                'purchase_number' => $purchase->purchase_number,
                'status' => $purchase->status,
                'grand_total' => (string) $purchase->grand_total,
            ],
            __('Purchase :number received into stock', ['number' => $purchase->purchase_number]),
        );

        return back()->with('success', __('Purchase :number received. Stock and payables updated.', ['number' => $purchase->purchase_number]));
    }
}
