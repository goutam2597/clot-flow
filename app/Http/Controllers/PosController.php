<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\SaleService;
use App\Support\AuditLogger;
use App\Support\Formatter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Point of Sale terminal.
 *
 * The terminal is intentionally thin: it resolves context (branch, warehouse,
 * open register session), exposes lookup endpoints for the cashier UI, and
 * delegates every monetary / inventory decision to SaleService. Totals shown
 * in the browser are estimates — the service recalculates everything from
 * product records inside a single transaction.
 */
class PosController extends Controller
{
    /**
     * Products pre-loaded into the terminal grid (search covers the rest).
     *
     * Sized to a real grocery assortment rather than a token few: a till that
     * only ever offers fifteen products sends the cashier to the search box for
     * every sale, which defeats the point of a grid. The payload stays small —
     * a name, two prices and an image URL each — so a couple of hundred costs a
     * few tens of kilobytes and still parses instantly.
     */
    private const TERMINAL_PRODUCT_LIMIT = 200;

    public function __construct(protected SaleService $sales) {}

    /* ------------------------------------------------------------------
     | Terminal
     | ------------------------------------------------------------------ */

    public function index(Request $request): View
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($user);
        $warehouses = $this->branchWarehouses($branchId);
        $warehouseId = $this->resolveWarehouseId($request, $warehouses);
        $warehouse = $warehouses->firstWhere('id', $warehouseId) ?? $warehouses->first();

        $currentSession = $this->currentSession($user);
        $currentSession?->loadMissing('user');

        $products = $this->terminalProducts($warehouse?->id);

        return view('pos.terminal', [
            'title' => __('Point of Sale'),
            'branch' => $branchId ? Branch::find($branchId) : null,
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'warehouseId' => $warehouse?->id,
            'products' => $products,
            // Only categories that actually appear in the loaded catalogue, so
            // the filter row never offers a chip that leads to an empty grid.
            'categories' => $this->terminalCategories($products),
            'customers' => $this->terminalCustomers($branchId),
            'paymentMethods' => PaymentMethod::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('rate')->get(),
            'registers' => $this->branchRegisters($branchId),
            'currentSession' => $currentSession,
            'sessionSales' => $this->sessionSales($currentSession),
            'recentSales' => $this->recentSales($branchId),
        ]);
    }

    /* ------------------------------------------------------------------
     | Lookup endpoints (JSON)
     | ------------------------------------------------------------------ */

    /**
     * Product / barcode lookup for the terminal.
     * An exact SKU or barcode match is returned first so scanners work instantly.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $warehouseId = $this->resolveWarehouseId(
            $request,
            $this->branchWarehouses($this->resolveBranchId($request->user()))
        );

        if ($term === '') {
            return response()->json(['products' => [], 'term' => $term]);
        }

        $query = Product::query()
            ->where('status', 'active')
            ->with(['taxRate', 'unit'])
            ->where(function ($q) use ($term) {
                $q->where('products.name', 'like', '%' . $term . '%')
                    ->orWhere('products.sku', 'like', '%' . $term . '%')
                    ->orWhere('products.barcode', 'like', '%' . $term . '%');
            });

        // Exact barcode / SKU hits win over fuzzy name matches.
        $exact = (clone $query)
            ->where(function ($q) use ($term) {
                $q->where('products.barcode', $term)->orWhere('products.sku', $term);
            })
            ->limit(1)
            ->get();

        $products = $exact->merge(
            (clone $query)
                ->whereNotIn('products.id', $exact->pluck('id'))
                ->orderBy('products.name')
                ->limit(30)
                ->get()
        );

        return response()->json([
            'term' => $term,
            'exact' => $exact->isNotEmpty(),
            'products' => $products
                ->map(fn (Product $product) => $this->productPayload($product, $warehouseId))
                ->values(),
        ]);
    }

    /**
     * Single product detail (used by barcode scans and keyboard lookups).
     */
    public function product(Request $request, Product $product): JsonResponse
    {
        abort_if($product->status !== 'active', 404);

        $product->load(['taxRate', 'unit']);
        $warehouseId = $this->resolveWarehouseId(
            $request,
            $this->branchWarehouses($this->resolveBranchId($request->user()))
        );

        return response()->json(['product' => $this->productPayload($product, $warehouseId)]);
    }

    /**
     * Customer lookup for the terminal's customer selector.
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $branchId = $this->resolveBranchId($request->user());

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', '%' . $term . '%')
                        ->orWhere('phone', 'like', '%' . $term . '%')
                        ->orWhere('code', 'like', '%' . $term . '%');
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'term' => $term,
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'phone' => $customer->phone,
                'type' => $customer->type,
                'credit_limit' => $this->decimal($customer->credit_limit, 2),
                'outstanding' => $this->decimal($customer->outstandingBalance(), 2),
            ])->values(),
        ]);
    }

    /* ------------------------------------------------------------------
     | Checkout
     | ------------------------------------------------------------------ */

    /**
     * Finalize a sale through SaleService (authoritative pricing, inventory
     * deduction and double-entry posting) using a client idempotency key so
     * retries and offline syncs can never duplicate a sale.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($user);
        $warehouses = $this->branchWarehouses($branchId);
        $expectsJson = $request->expectsJson();

        if (! $branchId) {
            return $this->fail($expectsJson, __('No branch is assigned to your user. Contact an administrator.'));
        }

        $validator = Validator::make($request->all(), [
            'warehouse_id' => ['required', 'integer', 'in:' . ($warehouses->pluck('id')->implode(',') ?: '0')],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'cash_register_session_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.tendered_amount' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            if ($expectsJson) {
                return response()->json([
                    'success' => false,
                    'message' => __('Please review the sale — some values are missing or invalid.'),
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            return back()->withInput()->withErrors($validator);
        }

        $data = $validator->validated();
        $warehouseId = (int) $data['warehouse_id'];

        // ---- Build authoritative lines from product records (never trust the UI) ----
        $items = [];
        foreach ($data['items'] as $line) {
            $product = Product::where('status', 'active')->with('taxRate')->find($line['product_id']);

            if (! $product) {
                return $this->fail($expectsJson, __('A selected product is no longer available. Refresh the terminal.'));
            }

            $quantity = $this->decimal($line['quantity'], 4);

            if (Money::isZero($quantity)) {
                return $this->fail(
                    $expectsJson,
                    __('Quantity must be greater than zero for :product.', ['product' => $product->name])
                );
            }

            // Guard stock up-front when the product is tracked and disallows negatives.
            if ($product->track_stock && ! $product->allow_negative_stock) {
                $available = $this->availableQuantity($product, $warehouseId);

                if (Money::cmp($quantity, $available) > 0) {
                    return $this->fail(
                        $expectsJson,
                        __('Insufficient stock for :product. Available: :qty.', [
                            'product' => $product->name,
                            'qty' => Formatter::number($available, 2),
                        ])
                    );
                }
            }

            $items[] = [
                'product_id' => $product->id,
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'tax_rate_id' => $product->tax_rate_id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => $this->decimal($product->selling_price, 4),
                'unit_cost' => $this->decimal($product->cost_price, 4),
                'discount_amount' => $this->decimal($line['discount_amount'] ?? '0', 2),
                'tax_rate' => $this->decimal($product->taxRate?->rate ?? '0', 4),
            ];
        }

        // ---- Payments (references enforced for methods that require them) ----
        $payments = [];
        foreach ($data['payments'] as $payment) {
            $method = PaymentMethod::where('is_active', true)->find($payment['payment_method_id']);

            if (! $method) {
                return $this->fail($expectsJson, __('A selected payment method is not active.'));
            }

            $amount = $this->decimal($payment['amount'], 2);
            $tendered = $this->decimal($payment['tendered_amount'] ?? $payment['amount'], 2);
            $tendered = Money::max($tendered, $amount, 2);
            $reference = isset($payment['reference']) ? trim((string) $payment['reference']) : '';

            if ($method->requires_reference && $reference === '') {
                return $this->fail(
                    $expectsJson,
                    __('A reference is required for :method payments.', ['method' => $method->name])
                );
            }

            $payments[] = [
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'tendered_amount' => $tendered,
                'reference' => $reference !== '' ? $reference : null,
            ];
        }

        $sessionId = ! empty($data['cash_register_session_id']) ? (int) $data['cash_register_session_id'] : null;

        if ($sessionId) {
            $session = CashRegisterSession::where('status', 'open')->find($sessionId);

            if (! $session) {
                return $this->fail($expectsJson, __('The selected cash register session is not open.'));
            }
        }

        $idempotencyKey = $this->normalizeKey($data['idempotency_key'] ?? null);

        $payload = [
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'cash_register_session_id' => $sessionId,
            'user_id' => $user->id,
            'customer_id' => $data['customer_id'] ?? null,
            'items' => $items,
            'payments' => $payments,
            'discount_total' => $this->decimal($data['discount_total'] ?? '0', 2),
            'shipping_total' => $this->decimal($data['shipping_total'] ?? '0', 2),
            'source' => 'online',
            'sold_at' => now(),
            'notes' => $data['notes'] ?? null,
        ];

        try {
            $sale = DB::transaction(function () use ($payload, $idempotencyKey, $sessionId, $user, $branchId) {
                // SaleService owns pricing, stock deduction and the journal entry.
                $sale = $this->sales->create($payload, $idempotencyKey);

                IdempotencyKey::updateOrCreate(
                    ['key' => $idempotencyKey],
                    [
                        'endpoint' => 'pos/sale',
                        'resource_type' => Sale::class,
                        'resource_id' => $sale->id,
                        'status' => 'completed',
                        'user_id' => $user->id,
                    ]
                );

                if ($sessionId) {
                    $this->syncSessionTotals($sessionId);
                }

                AuditLogger::log(
                    'sale.created',
                    Sale::class,
                    $sale->id,
                    null,
                    [
                        'invoice_number' => $sale->invoice_number,
                        'grand_total' => (string) $sale->grand_total,
                        'paid_total' => (string) $sale->paid_total,
                        'due_total' => (string) $sale->due_total,
                        'items' => count($payload['items']),
                    ],
                    __('Sale :invoice completed at the POS terminal', ['invoice' => $sale->invoice_number]),
                    $branchId
                );

                return $sale;
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->fail(
                $expectsJson,
                $e->getMessage() !== '' ? $e->getMessage() : __('The sale could not be completed. Please try again.')
            );
        }

        if ($expectsJson) {
            return response()->json([
                'success' => true,
                'message' => __('Sale :invoice completed.', ['invoice' => $sale->invoice_number]),
                'sale' => [
                    'id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'grand_total' => $this->decimal($sale->grand_total, 2),
                    'paid_total' => $this->decimal($sale->paid_total, 2),
                    'change_total' => $this->decimal($sale->change_total, 2),
                    'due_total' => $this->decimal($sale->due_total, 2),
                ],
                'receipt_url' => route('pos.receipt', $sale),
            ]);
        }

        return redirect()
            ->route('pos.receipt', $sale)
            ->with('success', __('Sale :invoice completed.', ['invoice' => $sale->invoice_number]));
    }

    /* ------------------------------------------------------------------
     | Receipt
     | ------------------------------------------------------------------ */

    public function receipt(Request $request, Sale $sale): View
    {
        $user = $request->user();
        abort_if($user->branch_id && (int) $sale->branch_id !== (int) $user->branch_id, 403);

        $sale->load([
            'items.product',
            'payments.paymentMethod',
            'customer',
            'user',
            'warehouse',
            'branch',
            'cashRegisterSession.cashRegister',
        ]);

        return view('pos.receipt', [
            'title' => __('Receipt :number', ['number' => $sale->invoice_number]),
            'sale' => $sale,
        ]);
    }

    /* ------------------------------------------------------------------
     | Internals
     | ------------------------------------------------------------------ */

    protected function fail(bool $expectsJson, string $message): JsonResponse|RedirectResponse
    {
        if ($expectsJson) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return back()->withInput()->with('error', $message);
    }

    protected function resolveBranchId($user): ?int
    {
        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return Branch::where('is_active', true)->where('is_default', true)->value('id')
            ?? Branch::where('is_active', true)->orderBy('id')->value('id');
    }

    protected function branchWarehouses(?int $branchId)
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    protected function resolveWarehouseId(Request $request, $warehouses): ?int
    {
        $requested = (int) $request->query('warehouse_id', 0);

        if ($requested && $warehouses->contains('id', $requested)) {
            return $requested;
        }

        return optional($warehouses->firstWhere('is_default', true))->id
            ?? optional($warehouses->first())->id;
    }

    protected function branchRegisters(?int $branchId)
    {
        return CashRegister::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();
    }

    /**
     * The open drawer session this terminal should post sales against.
     *
     * A session belongs to a REGISTER, not to a person — the drawer is open
     * whoever is standing at it. So the lookup is two-tier:
     *
     *   1. the signed-in user's own session, which is the right answer almost
     *      always and the one whose opening float they are responsible for;
     *   2. otherwise ANY session open on this branch, because the till is
     *      genuinely usable and warning the owner that nothing is open while a
     *      drawer sits open two metres away is simply wrong.
     *
     * Matching only on the user's own id — which is what this did — made every
     * manager and owner see a blocking "no register session is open" banner
     * during perfectly normal trading.
     */
    protected function currentSession($user): ?CashRegisterSession
    {
        $base = fn () => CashRegisterSession::query()
            ->where('status', 'open')
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id));

        return $base()
            ->where('user_id', $user->id)
            ->with('cashRegister')
            ->latest('opened_at')
            ->first()
            ?? $base()
                ->with('cashRegister')
                ->latest('opened_at')
                ->first();
    }

    protected function sessionSales(?CashRegisterSession $session): array
    {
        if (! $session) {
            return ['count' => 0, 'total' => '0.00'];
        }

        $query = Sale::query()
            ->where('cash_register_session_id', $session->id)
            ->where('status', 'completed')
            ->whereDate('sold_at', today());

        return [
            'count' => (clone $query)->count(),
            'total' => $this->decimal((clone $query)->sum('grand_total'), 2),
        ];
    }

    protected function recentSales(?int $branchId)
    {
        return Sale::query()
            ->where('status', 'completed')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('customer')
            ->latest('sold_at')
            ->limit(8)
            ->get();
    }

    /**
     * Products shown in the terminal grid, with available stock for the
     * selected warehouse attached (non-variant balances only).
     */
    protected function terminalProducts(?int $warehouseId)
    {
        return Product::query()
            ->where('status', 'active')
            ->with(['taxRate', 'unit'])
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->leftJoin('stocks', function ($join) use ($warehouseId) {
                    $join->on('stocks.product_id', '=', 'products.id')
                        ->where('stocks.warehouse_id', '=', $warehouseId)
                        ->whereNull('stocks.product_variant_id');
                });
            })
            ->select('products.*')
            ->selectRaw('COALESCE(stocks.quantity, 0) as stock_quantity')
            ->selectRaw('COALESCE(stocks.reserved_quantity, 0) as stock_reserved')
            ->orderBy('products.name')
            ->limit(self::TERMINAL_PRODUCT_LIMIT)
            ->get()
            ->map(function (Product $product) use ($warehouseId) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'category_id' => $product->category_id,
                    'price' => $this->decimal($product->selling_price, 4),
                    'cost' => $this->decimal($product->cost_price, 4),
                    'tax_rate_id' => $product->tax_rate_id,
                    'tax_rate' => $this->decimal($product->taxRate?->rate ?? '0', 4),
                    'tax_name' => $product->taxRate?->name,
                    'available' => $this->availableQuantity($product, $warehouseId),
                    'track_stock' => (bool) $product->track_stock,
                    'allow_negative_stock' => (bool) $product->allow_negative_stock,
                    'unit' => $product->unit?->name,
                    'image_url' => $product->image_url,
                ];
            })
            ->all();
    }

    /**
     * Categories represented in the terminal catalogue, with a count each.
     *
     * Derived from the loaded products rather than queried independently: a
     * filter chip that leads to an empty grid is worse than no chip at all,
     * and the terminal only ever shows a bounded slice of the catalogue.
     */
    protected function terminalCategories(array $products)
    {
        $counts = [];

        foreach ($products as $product) {
            $id = $product['category_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        if ($counts === []) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', array_keys($counts))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'count' => $counts[$category->id],
            ])
            ->values();
    }

    protected function terminalCustomers(?int $branchId)
    {
        return Customer::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'code', 'phone', 'type', 'credit_limit']);
    }

    /**
     * JSON shape consumed by the terminal's JavaScript.
     */
    protected function productPayload(Product $product, ?int $warehouseId): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'category_id' => $product->category_id,
            'price' => $this->decimal($product->selling_price, 4),
            'cost' => $this->decimal($product->cost_price, 4),
            'tax_rate_id' => $product->tax_rate_id,
            'tax_rate' => $this->decimal($product->taxRate?->rate ?? '0', 4),
            'tax_name' => $product->taxRate?->name,
            'available' => $this->availableQuantity($product, $warehouseId),
            'track_stock' => (bool) $product->track_stock,
            'allow_negative_stock' => (bool) $product->allow_negative_stock,
            'unit' => $product->unit?->name,
            'image_url' => $product->image_url,
        ];
    }

    /**
     * Available (quantity - reserved) balance for a product at a warehouse.
     */
    protected function availableQuantity(Product $product, ?int $warehouseId): string
    {
        if (! $warehouseId) {
            return '0';
        }

        // Already hydrated by the terminal grid query when present.
        if (isset($product->stock_quantity)) {
            $available = Money::sub((string) $product->stock_quantity, (string) $product->stock_reserved, 4);

            return Money::isNegative($available) ? '0' : $available;
        }

        $stock = Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();

        if (! $stock) {
            return '0';
        }

        return Money::max($stock->available_quantity, '0', 4);
    }

    /**
     * Keep the register session's running totals in step with its sales.
     */
    protected function syncSessionTotals(int $sessionId): void
    {
        $session = CashRegisterSession::find($sessionId);

        if (! $session) {
            return;
        }

        $query = Sale::query()
            ->where('cash_register_session_id', $session->id)
            ->where('status', 'completed');

        $session->update([
            'total_sales' => $this->decimal((clone $query)->sum('grand_total'), 2),
            'sale_count' => (clone $query)->count(),
        ]);
    }

    /**
     * Normalize a client supplied idempotency key to a stable token.
     */
    protected function normalizeKey(?string $key): string
    {
        $key = trim((string) $key);

        if ($key !== '' && preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $key)) {
            return $key;
        }

        return Str::uuid()->toString();
    }

    /**
     * Decimal string normalisation — never floats for money or quantities.
     */
    protected function decimal($value, int $scale = 2): string
    {
        $value = is_null($value) || $value === '' ? '0' : (string) $value;

        return number_format((float) $value, $scale, '.', '');
    }
}
