<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Number;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Product catalog CRUD. Products are the master records referenced by the POS,
 * purchases, inventory and accounting modules, so every write is audited and
 * wrapped in a transaction.
 */
class ProductController extends Controller
{
    protected int $perPage = 15;

    public function index(Request $request): View
    {
        $branchId = $request->user()->branch_id;

        $warehouseIds = $branchId
            ? Warehouse::where('branch_id', $branchId)->pluck('id')
            : Warehouse::pluck('id');

        // Safe to inline: integer ids read from the database.
        $warehouseList = $warehouseIds->isEmpty()
            ? '0'
            : implode(',', array_map('intval', $warehouseIds->all()));

        $stockSubQuery = '(SELECT COALESCE(SUM(s.quantity - s.reserved_quantity), 0)'
            . " FROM stocks s WHERE s.product_id = products.id AND s.warehouse_id IN ({$warehouseList}))";

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category_id' => $request->query('category_id'),
            'brand_id' => $request->query('brand_id'),
            'status' => $request->query('status'),
            'stock' => $request->query('stock'),
        ];

        $products = Product::query()
            ->with(['category', 'brand', 'unit', 'taxRate'])
            ->withSum(['stocks as stock_quantity' => fn ($q) => $q->whereIn('warehouse_id', $warehouseIds)], 'quantity')
            ->withSum(['stocks as reserved_quantity' => fn ($q) => $q->whereIn('warehouse_id', $warehouseIds)], 'reserved_quantity')
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('products.name', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('products.sku', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('products.barcode', 'like', '%' . $filters['q'] . '%');
                });
            })
            ->when($filters['category_id'], fn ($query) => $query->where('products.category_id', $filters['category_id']))
            ->when($filters['brand_id'], fn ($query) => $query->where('products.brand_id', $filters['brand_id']))
            ->when(in_array($filters['status'], ['active', 'inactive'], true), fn ($query) => $query->where('products.status', $filters['status']))
            ->when($filters['stock'] === 'low', function ($query) use ($stockSubQuery) {
                $query->where('products.track_stock', true)
                    ->whereRaw($stockSubQuery . ' > 0')
                    ->whereRaw($stockSubQuery . ' <= products.reorder_level');
            })
            ->when($filters['stock'] === 'out', fn ($query) => $query->whereRaw($stockSubQuery . ' <= 0'))
            ->orderBy('products.name')
            ->paginate($this->perPage)
            ->withQueryString();

        $lowStockCount = Product::where('track_stock', true)
            ->whereRaw($stockSubQuery . ' <= products.reorder_level')
            ->count();

        $stockValue = (string) Stock::whereIn('warehouse_id', $warehouseIds)
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->sum(DB::raw('stocks.quantity * stocks.avg_cost'));

        return view('products.index', [
            'title' => __('Products'),
            'products' => $products,
            'filters' => $filters,
            'stats' => [
                'total' => Product::count(),
                'active' => Product::where('status', 'active')->count(),
                'low_stock' => $lowStockCount,
                'stock_value' => Money::add($stockValue, '0', 2),
            ],
            'categories' => Category::orderBy('name')->get(['id', 'name', 'parent_id']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name']),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('name')->get(['id', 'name', 'rate']),
            'productTypes' => [
                'simple' => __('Simple'),
                'variable' => __('Variable'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->productData($request);

        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create($data);

            AuditLogger::log(
                'created',
                Product::class,
                $product->id,
                null,
                $product->toArray(),
                'Product created: ' . $product->name,
                $request->user()->branch_id
            );

            return $product;
        });

        return redirect()
            ->route('products.index')
            ->with('success', __('Product ":name" has been created.', ['name' => $product->name]));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->productData($request, $product);
        $original = $product->toArray();

        DB::transaction(function () use ($product, $data, $original, $request) {
            $product->update($data);

            AuditLogger::log(
                'updated',
                Product::class,
                $product->id,
                $original,
                $product->toArray(),
                'Product updated: ' . $product->name,
                $request->user()->branch_id
            );
        });

        return redirect()
            ->route('products.index')
            ->with('success', __('Product ":name" has been updated.', ['name' => $product->name]));
    }

    public function destroy(Product $product): RedirectResponse
    {
        $hasHistory = SaleItem::where('product_id', $product->id)->exists()
            || PurchaseItem::where('product_id', $product->id)->exists()
            || StockMovement::where('product_id', $product->id)->exists()
            || Stock::where('product_id', $product->id)->where('quantity', '!=', 0)->exists();

        if ($hasHistory) {
            return redirect()
                ->route('products.index')
                ->with('error', __('Product ":name" has transaction history. Set it to inactive instead of deleting it.', ['name' => $product->name]));
        }

        $original = $product->toArray();

        DB::transaction(function () use ($product, $original) {
            AuditLogger::log(
                'deleted',
                Product::class,
                $product->id,
                $original,
                null,
                'Product deleted: ' . $product->name
            );

            $product->delete();
        });

        return redirect()
            ->route('products.index')
            ->with('success', __('Product ":name" has been deleted.', ['name' => $product->name]));
    }

    /**
     * Validate the request and normalise it into a model-ready array.
     * Money is normalised through bc math, never floats.
     */
    protected function productData(Request $request, ?Product $product = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'type' => ['required', 'in:simple,variable'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ], [], [
            'name' => __('product name'),
            'sku' => __('SKU'),
            'category_id' => __('category'),
            'brand_id' => __('brand'),
            'unit_id' => __('unit'),
            'tax_rate_id' => __('tax rate'),
            'cost_price' => __('cost price'),
            'selling_price' => __('selling price'),
            'reorder_level' => __('reorder level'),
            'image' => __('image'),
            'image_url' => __('image URL'),
        ]);

        $data = [
            'category_id' => $validated['category_id'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'unit_id' => $validated['unit_id'] ?? null,
            'tax_rate_id' => $validated['tax_rate_id'] ?? null,
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'barcode' => $validated['barcode'] ?? null,
            'type' => $validated['type'],
            'description' => $validated['description'] ?? null,
            'cost_price' => Number::money((string) $request->input('cost_price')),
            'selling_price' => Number::money((string) $request->input('selling_price')),
            'track_stock' => $request->boolean('track_stock'),
            'allow_negative_stock' => $request->boolean('allow_negative_stock'),
            'reorder_level' => (int) $request->input('reorder_level', 0),
            'status' => $validated['status'],
        ];

        // The image column holds either a stored upload or an absolute URL,
        // and the three ways of setting it are mutually exclusive. Upload wins
        // over URL, and "remove" wins over both — otherwise a stale hidden
        // field would resurrect an image the user just deleted.
        $imageUrl = $validated['image_url'] ?? null;

        if ($request->boolean('remove_image')) {
            $this->deleteStoredImage($product);
            $data['image_path'] = null;
        } elseif ($request->hasFile('image')) {
            $this->deleteStoredImage($product);
            $data['image_path'] = $request->file('image')->store('products', 'public');
        } elseif (filled($imageUrl)) {
            $this->deleteStoredImage($product);
            $data['image_path'] = $imageUrl;
        } elseif ($product) {
            // Nothing submitted: keep whatever is already there rather than
            // blanking the image because the field was left alone.
            $data['image_path'] = $product->image_path;
        }

        return $data;
    }

    /**
     * Remove a previously uploaded file, never a remote one.
     *
     * Deleting a URL we merely reference would be wrong — we do not own the
     * bytes — and `image_path` holds both kinds.
     */
    protected function deleteStoredImage(?Product $product): void
    {
        $path = $product?->image_path;

        if (blank($path) || preg_match('#^https?://#i', $path)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}

