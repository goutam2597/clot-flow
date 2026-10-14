<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Number;
use App\Support\NumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Stock levels, manual adjustments and warehouse-to-warehouse transfers.
 * Every quantity change goes through InventoryService so the stock balance and
 * the auditable movement record are always written together.
 */
class InventoryController extends Controller
{
    protected int $perPage = 15;

    public function index(Request $request): View
    {
        $branchId = $request->user()->branch_id;

        $warehouseIds = $branchId
            ? Warehouse::where('branch_id', $branchId)->pluck('id')
            : Warehouse::pluck('id');

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'warehouse_id' => $request->query('warehouse_id'),
            'category_id' => $request->query('category_id'),
            'stock' => $request->query('stock'),
        ];

        $stocks = Stock::query()
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('stocks.warehouse_id', $warehouseIds)
            ->select(
                'stocks.*',
                'products.name as product_name',
                'products.sku as product_sku',
                'products.unit_id as product_unit_id',
                'products.track_stock as track_stock',
                'products.reorder_level as reorder_level',
                'warehouses.name as warehouse_name',
                'categories.name as category_name'
            )
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('products.name', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('products.sku', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('products.barcode', 'like', '%' . $filters['q'] . '%');
                });
            })
            ->when($filters['warehouse_id'], fn ($query) => $query->where('stocks.warehouse_id', $filters['warehouse_id']))
            ->when($filters['category_id'], fn ($query) => $query->where('products.category_id', $filters['category_id']))
            ->when($filters['stock'] === 'low', function ($query) {
                $query->where('products.track_stock', true)
                    ->whereRaw('stocks.quantity <= products.reorder_level');
            })
            ->when($filters['stock'] === 'out', fn ($query) => $query->whereRaw('stocks.quantity <= 0'))
            ->orderBy('products.name')
            ->paginate($this->perPage)
            ->withQueryString();

        $baseQuery = Stock::query()
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->whereIn('stocks.warehouse_id', $warehouseIds);

        $stockValue = (string) (clone $baseQuery)->sum(DB::raw('stocks.quantity * stocks.avg_cost'));

        $lowStockLines = (clone $baseQuery)
            ->where('products.track_stock', true)
            ->whereRaw('stocks.quantity <= products.reorder_level')
            ->count();

        $outOfStockLines = (clone $baseQuery)->whereRaw('stocks.quantity <= 0')->count();

        return view('inventory.index', [
            'title' => __('Inventory'),
            'stocks' => $stocks,
            'filters' => $filters,
            'stats' => [
                'lines' => (clone $baseQuery)->count(),
                'stock_value' => Money::add($stockValue, '0', 2),
                'low_stock' => $lowStockLines,
                'out_of_stock' => $outOfStockLines,
            ],
            'warehouses' => Warehouse::when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function adjust(Request $request, InventoryService $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'direction' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string', 'max:191'],
        ], [], [
            'warehouse_id' => __('warehouse'),
            'product_id' => __('product'),
            'quantity' => __('quantity'),
            'unit_cost' => __('unit cost'),
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $warehouseId = (int) $validated['warehouse_id'];
        $quantity = Number::qty((string) $validated['quantity']);
        $memo = $validated['memo'] ?? __('Manual stock adjustment');

        // Increases need a unit cost so the weighted average cost stays correct.
        $unitCost = Number::money((string) $request->input('unit_cost', '0'));
        if ($validated['direction'] === 'increase' && Money::isZero($unitCost)) {
            $unitCost = Number::money((string) $product->cost_price);
        }

        try {
            DB::transaction(function () use ($inventory, $validated, $warehouseId, $product, $quantity, $unitCost, $memo, $request) {
                $movement = $validated['direction'] === 'increase'
                    ? $inventory->increase($warehouseId, $product->id, null, $quantity, $unitCost, 'adjustment', null, $request->user()->id, $memo)
                    : $inventory->decrease($warehouseId, $product->id, null, $quantity, $unitCost, 'adjustment', null, $request->user()->id, $memo);

                AuditLogger::log(
                    'inventory_adjusted',
                    Product::class,
                    $product->id,
                    null,
                    [
                        'warehouse_id' => $warehouseId,
                        'product_id' => $product->id,
                        'direction' => $validated['direction'],
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'balance_after' => $movement->balance_after,
                        'memo' => $memo,
                    ],
                    'Stock adjustment: ' . $product->name,
                    $request->user()->branch_id
                );
            });
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('inventory.index')
            ->with('success', __('Stock for ":name" has been adjusted.', ['name' => $product->name]));
    }

    public function transfers(Request $request): View
    {
        $branchId = $request->user()->branch_id;

        $warehouseIds = $branchId
            ? Warehouse::where('branch_id', $branchId)->pluck('id')
            : Warehouse::pluck('id');

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status'),
        ];

        $transfers = StockTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'user'])
            ->withCount('items')
            ->where(function ($query) use ($warehouseIds) {
                $query->whereIn('from_warehouse_id', $warehouseIds)
                    ->orWhereIn('to_warehouse_id', $warehouseIds);
            })
            ->when($filters['q'] !== '', fn ($query) => $query->where('transfer_number', 'like', '%' . $filters['q'] . '%'))
            ->when(in_array($filters['status'], ['pending', 'completed', 'cancelled'], true), fn ($query) => $query->where('status', $filters['status']))
            ->latest('transferred_at')
            ->paginate($this->perPage)
            ->withQueryString();

        return view('inventory.transfers', [
            'title' => __('Stock Transfers'),
            'transfers' => $transfers,
            'filters' => $filters,
            'warehouses' => Warehouse::when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku', 'unit_id']),
            'stats' => [
                'total' => StockTransfer::count(),
                'completed' => StockTransfer::where('status', 'completed')->count(),
                'pending' => StockTransfer::where('status', 'pending')->count(),
                'this_month' => StockTransfer::whereBetween('transferred_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            ],
        ]);
    }

    public function storeTransfer(Request $request, InventoryService $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:191'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], [], [
            'from_warehouse_id' => __('source warehouse'),
            'to_warehouse_id' => __('destination warehouse'),
            'items' => __('transfer lines'),
            'items.*.product_id' => __('product'),
            'items.*.quantity' => __('quantity'),
        ]);

        $fromWarehouseId = (int) $validated['from_warehouse_id'];
        $toWarehouseId = (int) $validated['to_warehouse_id'];
        $notes = $validated['notes'] ?? null;
        $userId = $request->user()->id;

        try {
            $transfer = DB::transaction(function () use ($validated, $inventory, $fromWarehouseId, $toWarehouseId, $notes, $userId, $request) {
                $transfer = StockTransfer::create([
                    'transfer_number' => NumberGenerator::next('TRF'),
                    'from_warehouse_id' => $fromWarehouseId,
                    'to_warehouse_id' => $toWarehouseId,
                    'user_id' => $userId,
                    'status' => 'completed',
                    'notes' => $notes,
                    'transferred_at' => now(),
                ]);

                foreach ($validated['items'] as $line) {
                    $product = Product::findOrFail($line['product_id']);
                    $quantity = Number::qty((string) $line['quantity']);

                    $sourceStock = Stock::where('warehouse_id', $fromWarehouseId)
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->first();

                    $unitCost = Number::money((string) ($sourceStock->avg_cost ?? $product->cost_price));

                    $inventory->decrease($fromWarehouseId, $product->id, null, $quantity, $unitCost, 'transfer_out', $transfer, $userId, $notes);
                    $inventory->increase($toWarehouseId, $product->id, null, $quantity, $unitCost, 'transfer_in', $transfer, $userId, $notes);

                    StockTransferItem::create([
                        'stock_transfer_id' => $transfer->id,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'quantity' => $quantity,
                    ]);
                }

                AuditLogger::log(
                    'stock_transferred',
                    StockTransfer::class,
                    $transfer->id,
                    null,
                    [
                        'transfer_number' => $transfer->transfer_number,
                        'from_warehouse_id' => $fromWarehouseId,
                        'to_warehouse_id' => $toWarehouseId,
                        'lines' => count($validated['items']),
                    ],
                    'Stock transfer created: ' . $transfer->transfer_number,
                    $request->user()->branch_id
                );

                return $transfer;
            });
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('inventory.transfers')
            ->with('success', __('Transfer :number has been completed.', ['number' => $transfer->transfer_number]));
    }
}

