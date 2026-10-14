<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\AccountingService;
use App\Services\InventoryService;
use App\Support\AuditLogger;
use App\Support\Formatter;
use App\Support\Money;
use App\Support\NumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Sales register, sale detail/invoice, and the returns & refunds workflow.
 *
 * Sales are created by the POS (PosController -> SaleService). This controller
 * is read-oriented plus the two document-level corrections the ledger needs:
 * voiding a sale (full reversal) and returning part of a sale (partial refund).
 *
 * Both corrections run inside a single database transaction and reuse the
 * authoritative services so that stock balances and the double-entry journal
 * stay consistent with the original posting:
 *   - stock is restored through InventoryService (audited stock movements),
 *   - the ledger is corrected through AccountingService (reversing entries /
 *     a balanced contra entry) — posted entries are never edited.
 */
class SaleController extends Controller
{
    /** Sale lifecycle states (see sales.status column comment). */
    public const SALE_STATUSES = ['completed', 'held', 'voided', 'returned'];

    /** Payment states (see sales.payment_status column comment). */
    public const PAYMENT_STATUSES = ['paid', 'partial', 'due'];

    /** Capture channels. */
    public const SOURCES = ['online', 'offline'];

    /** Offline queue states. */
    public const SYNC_STATUSES = ['synced', 'pending', 'syncing', 'failed', 'attention'];

    /** Return document states (see sale_returns.status column comment). */
    public const RETURN_STATUSES = ['completed', 'voided'];

    /** Allowed page sizes for the paginated registers. */
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public function __construct(
        protected InventoryService $inventory,
        protected AccountingService $accounting,
    ) {}

    /* ------------------------------------------------------------------
     |  Sales register
     | ------------------------------------------------------------------ */

    public function index(Request $request): View
    {
        $filters = $this->saleFilters($request);

        $query = Sale::query()
            /*
             * Every relation the row template reads has to be eager-loaded.
             * Lazy loading is disabled outside production precisely so an N+1
             * that would cost 200 queries on a full page fails loudly here
             * instead of quietly degrading production.
             *
             * customer  — name and phone
             * user       — the cashier's name
             * warehouse  — the stock location the sale drew from
             * branch     — shown beside the cashier on wide terminals
             */
            /*
             * Every relation the row template reads has to be eager-loaded.
             * Lazy loading is disabled outside production precisely so an N+1
             * that would cost 200 queries on a full page fails loudly here
             * instead of quietly degrading production.
             *
             * customer  — name and phone
             * user       — the cashier's name
             * warehouse  — the stock location the sale drew from
             * branch     — shown beside the cashier
             */
            ->with(['customer', 'user', 'warehouse', 'branch'])
            ->when($filters['branch_id'], fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($filters['status'] !== '', fn ($q, $status) => $q->where('status', $status))
            ->when($filters['payment_status'] !== '', fn ($q, $paymentStatus) => $q->where('payment_status', $paymentStatus))
            ->when($filters['source'] !== '', fn ($q, $source) => $q->where('source', $source))
            ->when($filters['sync_status'] !== '', fn ($q, $syncStatus) => $q->where('sync_status', $syncStatus))
            ->when($filters['from'], fn ($q, $from) => $q->where('sold_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('sold_at', '<=', $to))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = $filters['search'];

                $q->where(function ($inner) use ($term) {
                    $inner->where('invoice_number', 'like', '%' . $term . '%')
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('name', 'like', '%' . $term . '%')
                            ->orWhere('phone', 'like', '%' . $term . '%'));
                });
            });

        $summary = [
            'count' => (clone $query)->count(),
            'grand_total' => (string) (clone $query)->sum('grand_total'),
            'paid_total' => (string) (clone $query)->sum('paid_total'),
            'due_total' => (string) (clone $query)->sum('due_total'),
            'voided' => (clone $query)->where('status', 'voided')->count(),
        ];

        $sales = $query->latest('sold_at')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'summary' => $summary,
            'filters' => $filters,
            'statuses' => self::SALE_STATUSES,
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'sources' => self::SOURCES,
            'syncStatuses' => self::SYNC_STATUSES,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function show(Sale $sale): View
    {
        $this->loadSaleRelations($sale);

        $quantities = $this->returnQuantities($sale);

        return view('sales.show', [
            'sale' => $sale,
            'returned' => $quantities['returned'],
            'returnable' => $quantities['returnable'],
            'journalEntries' => $this->saleJournalEntries($sale),
        ]);
    }

    /**
     * Printable tax invoice / customer receipt for a sale.
     */
    public function invoice(Sale $sale): View
    {
        $this->loadSaleRelations($sale);

        $quantities = $this->returnQuantities($sale);

        return view('sales.invoice', [
            'sale' => $sale,
            'returned' => $quantities['returned'],
            'returnable' => $quantities['returnable'],
        ]);
    }

    /* ------------------------------------------------------------------
     |  Returns & refunds register
     | ------------------------------------------------------------------ */

    public function returns(Request $request): View
    {
        $branchId = $request->user()->branch_id;

        $filters = $this->returnFilters($request);

        $query = SaleReturn::query()
            ->with(['sale', 'customer', 'user'])
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['status'] !== '', fn ($q, $status) => $q->where('status', $status))
            ->when($filters['restock'] !== '', fn ($q, $restock) => $q->where('restock', $restock === 'yes'))
            ->when($filters['from'], fn ($q, $from) => $q->where('returned_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('returned_at', '<=', $to))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = $filters['search'];

                $q->where(function ($inner) use ($term) {
                    $inner->where('return_number', 'like', '%' . $term . '%')
                        ->orWhereHas('sale', fn ($sale) => $sale->where('invoice_number', 'like', '%' . $term . '%'))
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('name', 'like', '%' . $term . '%')
                            ->orWhere('phone', 'like', '%' . $term . '%'));
                });
            });

        $summary = [
            'count' => (clone $query)->count(),
            'refunded_total' => (string) (clone $query)->sum('refunded_total'),
            'grand_total' => (string) (clone $query)->sum('grand_total'),
            'restocked' => (clone $query)->where('restock', true)->count(),
        ];

        $returns = $query->latest('returned_at')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('sales.returns.index', [
            'returns' => $returns,
            'summary' => $summary,
            'filters' => $filters,
            'statuses' => self::RETURN_STATUSES,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function returnShow(SaleReturn $saleReturn): View
    {
        $saleReturn->load([
            'sale',
            'sale.branch',
            'sale.user',
            'customer',
            'user',
            'branch',
            'warehouse',
            'items.saleItem',
            'items.product',
        ]);

        return view('sales.returns.show', [
            'saleReturn' => $saleReturn,
            'journalEntries' => $this->returnJournalEntries($saleReturn),
        ]);
    }

    /* ------------------------------------------------------------------
     |  Return a sale (partial refund)
     | ------------------------------------------------------------------ */

    public function returnForm(Sale $sale): View
    {
        $sale->load(['customer', 'user', 'items.product']);

        if (! in_array($sale->status, ['completed', 'returned'], true)) {
            return redirect()->route('sales.show', $sale)
                ->with('error', __('Only a completed sale can be returned.'));
        }

        $quantities = $this->returnQuantities($sale);

        return view('sales.returns.create', [
            'sale' => $sale,
            'returned' => $quantities['returned'],
            'returnable' => $quantities['returnable'],
            'reasons' => $this->returnReasons(),
        ]);
    }

    public function storeReturn(Request $request, Sale $sale): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gte:0'],
            'reason' => ['nullable', 'string', 'max:191'],
            'restock' => ['nullable', 'boolean'],
        ], [], [
            'items.*.sale_item_id' => __('sale item'),
            'items.*.quantity' => __('return quantity'),
        ]);

        $restock = $request->boolean('restock');
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        // Keep only positive quantities that belong to this sale.
        $requested = [];
        foreach ($validated['items'] as $row) {
            $quantity = (string) $row['quantity'];

            if (Money::isNegative($quantity) || Money::isZero($quantity)) {
                continue;
            }

            $requested[(int) $row['sale_item_id']] = $quantity;
        }

        $sale->loadMissing('items');

        $lines = [];
        foreach ($sale->items as $item) {
            if (! array_key_exists($item->id, $requested)) {
                continue;
            }

            $quantity = $requested[$item->id];
            $returnable = $this->returnableQuantity($item);

            if (Money::cmp($quantity, $returnable) > 0) {
                return back()->withInput()->withErrors([
                    "items.{$item->id}.quantity" => __('Maximum returnable quantity for :product is :quantity.', [
                        'product' => $item->product_name,
                        'quantity' => Formatter::number($returnable, 4),
                    ]),
                ]);
            }

            $lines[] = ['item' => $item, 'quantity' => $quantity];
        }

        if ($lines === []) {
            return back()->withInput()
                ->with('error', __('Enter a return quantity for at least one product.'));
        }

        try {
            $saleReturn = DB::transaction(function () use ($sale, $lines, $restock, $reason, $request) {
                // Lock the parent sale so concurrent returns cannot over-return.
                $locked = Sale::lockForUpdate()->findOrFail($sale->id);
                $locked->load('items');

                if (! in_array($locked->status, ['completed', 'returned'], true)) {
                    throw new RuntimeException(__('Only a completed sale can be returned.'));
                }

                $subtotal = '0.00';
                $taxTotal = '0.00';
                $costTotal = '0.00';
                $returnLines = [];

                foreach ($lines as $line) {
                    /** @var SaleItem $item */
                    $item = $line['item'];
                    $lockedItem = $locked->items->firstWhere('id', $item->id);

                    if (! $lockedItem) {
                        throw new RuntimeException(__('This product no longer belongs to the sale.'));
                    }

                    $quantity = $line['quantity'];
                    $returnable = $this->returnableQuantity($lockedItem);

                    if (Money::cmp($quantity, $returnable) > 0) {
                        throw new RuntimeException(__('The return quantity for :product exceeds the returnable quantity.', [
                            'product' => $lockedItem->product_name,
                        ]));
                    }

                    // Prorate line discount and tax by the returned share so that a
                    // full return reproduces the original line exactly.
                    $ratio = Money::div($quantity, (string) $lockedItem->quantity, 6);
                    $gross = Money::mul((string) $lockedItem->unit_price, $quantity, 4);
                    $discount = Money::mul((string) $lockedItem->discount_amount, $ratio, 2);
                    $net = Money::sub($gross, $discount, 4);
                    $tax = Money::mul((string) $lockedItem->tax_amount, $ratio, 2);
                    $lineTotal = Money::add($net, $tax, 2);
                    $lineCost = Money::mul((string) $lockedItem->unit_cost, $quantity, 4);

                    $subtotal = Money::add($subtotal, $net, 2);
                    $taxTotal = Money::add($taxTotal, $tax, 2);
                    $costTotal = Money::add($costTotal, $lineCost, 2);

                    $returnLines[] = [
                        'sale_item_id' => $lockedItem->id,
                        'product_id' => $lockedItem->product_id,
                        'product_variant_id' => $lockedItem->product_variant_id,
                        'quantity' => $quantity,
                        'unit_price' => (string) $lockedItem->unit_price,
                        'unit_cost' => (string) $lockedItem->unit_cost,
                        'tax_amount' => $tax,
                        'line_total' => $lineTotal,
                        'product_name' => $lockedItem->product_name,
                    ];
                }

                $grandTotal = Money::add($subtotal, $taxTotal, 2);

                $saleReturn = SaleReturn::create([
                    'return_number' => NumberGenerator::next('SRN'),
                    'sale_id' => $locked->id,
                    'branch_id' => $locked->branch_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'customer_id' => $locked->customer_id,
                    'user_id' => $request->user()->id,
                    'subtotal' => $subtotal,
                    'tax_total' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'refunded_total' => $grandTotal,
                    'status' => 'completed',
                    'restock' => $restock,
                    'reason' => $reason,
                    'returned_at' => now(),
                ]);

                foreach ($returnLines as $row) {
                    SaleReturnItem::create([
                        'sale_return_id' => $saleReturn->id,
                        'sale_item_id' => $row['sale_item_id'],
                        'product_id' => $row['product_id'],
                        'product_variant_id' => $row['product_variant_id'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'tax_amount' => $row['tax_amount'],
                        'line_total' => $row['line_total'],
                    ]);
                }

                // Restock through the authoritative inventory service so every
                // return writes an audited stock movement.
                if ($restock) {
                    foreach ($returnLines as $row) {
                        $this->inventory->increase(
                            $locked->warehouse_id,
                            $row['product_id'],
                            $row['product_variant_id'],
                            $row['quantity'],
                            $row['unit_cost'],
                            'return',
                            $saleReturn,
                            $request->user()->id,
                            __('Restock from return :number', ['number' => $saleReturn->return_number]),
                        );
                    }
                }

                // Ledger: contra revenue + output-tax reversal vs refund paid out.
                $this->postReturnJournal($saleReturn, $subtotal, $taxTotal, $grandTotal, $restock ? $costTotal : '0.00', $request);

                $dueTotal = Money::sub((string) $locked->due_total, $grandTotal, 2);
                if (Money::isNegative($dueTotal)) {
                    $dueTotal = '0.00';
                }

                $locked->update([
                    'due_total' => $dueTotal,
                    'status' => $this->saleStatusAfterReturn($locked),
                    'payment_status' => Money::isZero($dueTotal)
                        ? 'paid'
                        : (Money::isZero((string) $locked->paid_total) ? 'due' : 'partial'),
                ]);

                AuditLogger::log(
                    'sale.return.created',
                    SaleReturn::class,
                    $saleReturn->id,
                    null,
                    [
                        'return_number' => $saleReturn->return_number,
                        'sale_id' => $locked->id,
                        'invoice_number' => $locked->invoice_number,
                        'subtotal' => $subtotal,
                        'tax_total' => $taxTotal,
                        'grand_total' => $grandTotal,
                        'restock' => $restock,
                        'reason' => $reason,
                        'lines' => count($returnLines),
                    ],
                    __('Return :return for invoice :invoice', [
                        'return' => $saleReturn->return_number,
                        'invoice' => $locked->invoice_number,
                    ]),
                );

                return $saleReturn;
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.returns.show', $saleReturn)
            ->with('success', __('Return :number recorded. Stock and ledger were updated.', [
                'number' => $saleReturn->return_number,
            ]));
    }

    /* ------------------------------------------------------------------
     |  Void a sale (full reversal)
     | ------------------------------------------------------------------ */

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:191'],
        ]);

        $reason = trim((string) $validated['reason']);

        try {
            DB::transaction(function () use ($sale, $reason, $request) {
                // Serialise concurrent void/return attempts on the same sale.
                $locked = Sale::lockForUpdate()->findOrFail($sale->id);
                $locked->load('items');

                if ($locked->status !== 'completed') {
                    throw new RuntimeException(__('Only a completed sale can be voided.'));
                }

                // 1. Return every sold unit to the selling warehouse.
                foreach ($locked->items as $item) {
                    $this->inventory->increase(
                        $locked->warehouse_id,
                        $item->product_id,
                        $item->product_variant_id,
                        (string) $item->quantity,
                        (string) $item->unit_cost,
                        'adjustment',
                        $locked,
                        $request->user()->id,
                        __('Void of invoice :number', ['number' => $locked->invoice_number]),
                    );
                }

                // 2. Reverse every posted journal entry raised by this sale.
                $entries = JournalEntry::query()
                    ->where('reference_type', Sale::class)
                    ->where('reference_id', $locked->id)
                    ->where('status', 'posted')
                    ->lockForUpdate()
                    ->get();

                foreach ($entries as $entry) {
                    $this->accounting->reverseEntry(
                        $entry,
                        __('Void of invoice :number', ['number' => $locked->invoice_number]),
                    );
                }

                $old = [
                    'status' => $locked->status,
                    'payment_status' => $locked->payment_status,
                    'due_total' => (string) $locked->due_total,
                ];

                $locked->update([
                    'status' => 'voided',
                    'due_total' => '0.00',
                    'notes' => trim(($locked->notes ? $locked->notes . ' ' : '') . '[' . __('Voided') . ': ' . $reason . ']'),
                ]);

                AuditLogger::log(
                    'sale.voided',
                    Sale::class,
                    $locked->id,
                    $old,
                    [
                        'status' => 'voided',
                        'due_total' => '0.00',
                        'reason' => $reason,
                        'reversed_entries' => $entries->pluck('entry_number')->all(),
                        'restocked_items' => $locked->items->count(),
                    ],
                    __('Sale :number voided: :reason', [
                        'number' => $locked->invoice_number,
                        'reason' => $reason,
                    ]),
                );
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)
            ->with('success', __('Sale :number was voided. Inventory and ledger entries were reversed.', [
                'number' => $sale->invoice_number,
            ]));
    }

    /**
     * Normalised, validated filter set for the sales register.
     */
    protected function saleFilters(Request $request): array
    {
        $perPage = (int) $request->query('per_page', 20);

        return [
            'branch_id' => $request->user()->branch_id,
            'search' => trim((string) $request->query('search', '')),
            'status' => $this->allowed($request->query('status'), self::SALE_STATUSES),
            'payment_status' => $this->allowed($request->query('payment_status'), self::PAYMENT_STATUSES),
            'source' => $this->allowed($request->query('source'), self::SOURCES),
            'sync_status' => $this->allowed($request->query('sync_status'), self::SYNC_STATUSES),
            'from' => $this->parseDate((string) $request->query('from', ''), false),
            'to' => $this->parseDate((string) $request->query('to', ''), true),
            'from_raw' => trim((string) $request->query('from', '')),
            'to_raw' => trim((string) $request->query('to', '')),
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 20,
        ];
    }

    /**
     * Normalised, validated filter set for the returns register.
     */
    protected function returnFilters(Request $request): array
    {
        $perPage = (int) $request->query('per_page', 20);
        $restock = (string) $request->query('restock', '');

        return [
            'search' => trim((string) $request->query('search', '')),
            'status' => $this->allowed($request->query('status'), self::RETURN_STATUSES),
            'restock' => in_array($restock, ['yes', 'no'], true) ? $restock : '',
            'from' => $this->parseDate((string) $request->query('from', ''), false),
            'to' => $this->parseDate((string) $request->query('to', ''), true),
            'from_raw' => trim((string) $request->query('from', '')),
            'to_raw' => trim((string) $request->query('to', '')),
            'per_page' => in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 20,
        ];
    }

    /**
     * Whitelist a query-string value against a set of allowed options.
     */
    protected function allowed($value, array $options): string
    {
        $value = (string) $value;

        return in_array($value, $options, true) ? $value : '';
    }

    /**
     * Parse a user supplied date, ignoring malformed input.
     */
    protected function parseDate(string $value, bool $endOfDay): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    protected function loadSaleRelations(Sale $sale): void
    {
        $sale->load([
            'customer',
            'user',
            'branch',
            'warehouse',
            'cashRegisterSession',
            'items.product',
            'payments.paymentMethod',
            'returns' => fn ($q) => $q->latest('returned_at'),
            'returns.items',
        ]);
    }

    /**
     * Posted journal entries raised for a sale (original + any reversal).
     */
    protected function saleJournalEntries(Sale $sale)
    {
        return JournalEntry::query()
            ->with(['lines.account', 'reversedBy'])
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->latest('entry_date')
            ->latest('id')
            ->get();
    }

    /**
     * Posted journal entries raised for a return document.
     */
    protected function returnJournalEntries(SaleReturn $saleReturn)
    {
        return JournalEntry::query()
            ->with(['lines.account', 'reversedBy'])
            ->where('reference_type', SaleReturn::class)
            ->where('reference_id', $saleReturn->id)
            ->latest('entry_date')
            ->latest('id')
            ->get();
    }

    /**
     * Returned and still-returnable quantities per sale line (scale 4).
     */
    protected function returnQuantities(Sale $sale): array
    {
        $sale->loadMissing('items');

        $itemIds = $sale->items->pluck('id')->all();

        $returned = SaleReturnItem::query()
            ->whereIn('sale_item_id', $itemIds ?: [0])
            ->whereHas('saleReturn', fn ($q) => $q->where('status', 'completed'))
            ->get()
            ->groupBy('sale_item_id')
            ->map(fn ($rows) => Money::sum($rows->map(fn ($row) => (string) $row->quantity)->all(), 4));

        $returnable = [];
        foreach ($sale->items as $item) {
            $remaining = Money::sub((string) $item->quantity, (string) ($returned[$item->id] ?? '0'), 4);
            $returnable[$item->id] = Money::isNegative($remaining) ? '0' : $remaining;
        }

        return ['returned' => $returned, 'returnable' => $returnable];
    }

    /**
     * Quantity still allowed to be returned for a single sale line.
     */
    protected function returnableQuantity(SaleItem $item): string
    {
        $returned = (string) SaleReturnItem::query()
            ->where('sale_item_id', $item->id)
            ->whereHas('saleReturn', fn ($q) => $q->where('status', 'completed'))
            ->sum('quantity');

        $remaining = Money::sub((string) $item->quantity, $returned, 4);

        return Money::isNegative($remaining) ? '0' : $remaining;
    }

    /**
     * A sale is "returned" once every line has been fully returned.
     */
    protected function saleStatusAfterReturn(Sale $sale): string
    {
        foreach ($sale->items as $item) {
            if (! Money::isZero($this->returnableQuantity($item))) {
                return 'completed';
            }
        }

        return 'returned';
    }

    /**
     * Post the balanced contra entry for a return/refund.
     *
     * Dr Sales Returns (contra revenue)   subtotal (net)
     * Dr Output Tax Payable               tax_total
     *     Cr Cash / AR                    grand_total
     * Dr Inventory                        cost_total      (only when restocking)
     *     Cr Cost of Goods Sold           cost_total
     */
    protected function postReturnJournal(
        SaleReturn $saleReturn,
        string $subtotal,
        string $taxTotal,
        string $grandTotal,
        string $costTotal,
        Request $request
    ): void {
        if (Money::isZero($grandTotal) && Money::isZero($costTotal)) {
            return;
        }

        $lines = [];

        if (! Money::isZero($subtotal)) {
            $lines[] = [
                'account_id' => $this->systemAccount('4000')->id,
                'debit' => $subtotal,
                'credit' => '0',
                'description' => __('Sales returns'),
            ];
        }

        if (! Money::isZero($taxTotal)) {
            $lines[] = [
                'account_id' => $this->systemAccount('2100')->id,
                'debit' => $taxTotal,
                'credit' => '0',
                'description' => __('Output tax reversal'),
            ];
        }

        $lines[] = [
            'account_id' => $this->systemAccount('1000')->id,
            'debit' => '0',
            'credit' => $grandTotal,
            'description' => __('Refund to customer'),
        ];

        if (! Money::isZero($costTotal)) {
            $lines[] = [
                'account_id' => $this->systemAccount('1300')->id,
                'debit' => $costTotal,
                'credit' => '0',
                'description' => __('Inventory restocked'),
            ];
            $lines[] = [
                'account_id' => $this->systemAccount('5000')->id,
                'debit' => '0',
                'credit' => $costTotal,
                'description' => __('Cost of goods sold reversal'),
            ];
        }

        $this->accounting->postEntry(
            now()->toDateString(),
            $lines,
            __('Return :number', ['number' => $saleReturn->return_number]),
            SaleReturn::class,
            $saleReturn->id,
            $saleReturn->branch_id,
            $request->user()->id,
            null,
        );
    }

    /**
     * Resolve a chart-of-accounts code, failing loudly when unseeded.
     */
    protected function systemAccount(string $code): Account
    {
        $account = Account::where('code', $code)->first();

        if (! $account) {
            throw new RuntimeException(__('Required account ":code" is missing from the chart of accounts.', ['code' => $code]));
        }

        return $account;
    }

    /**
     * Common return reasons offered in the return form.
     */
    protected function returnReasons(): array
    {
        return [
            'damaged' => __('Damaged product'),
            'wrong_item' => __('Wrong item supplied'),
            'not_needed' => __('Customer changed mind'),
            'expired' => __('Expired / spoiled'),
            'over_supplied' => __('Over supplied quantity'),
            'pricing_error' => __('Pricing error'),
            'other' => __('Other'),
        ];
    }
}
