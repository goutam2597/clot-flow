<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Number;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Supplier master data plus the outstanding payable balance derived from
 * received / partially received purchases.
 */
class SupplierController extends Controller
{
    protected int $perPage = 15;

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status'),
            'dues' => $request->query('dues'),
        ];

        $suppliers = Supplier::query()
            ->withSum(['purchases as outstanding_payable' => fn ($q) => $q->whereIn('status', ['received', 'partial'])], 'due_total')
            ->withCount(['purchases as purchases_count' => fn ($q) => $q->whereIn('status', ['received', 'partial'])])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('name', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('company', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('phone', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('email', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('code', 'like', '%' . $filters['q'] . '%');
                });
            })
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($filters['dues'] === 'due', fn ($query) => $query->whereHas('purchases', fn ($q) => $q->whereIn('status', ['received', 'partial'])->where('due_total', '>', 0)))
            ->orderBy('name')
            ->paginate($this->perPage)
            ->withQueryString();

        $payables = (string) Purchase::whereIn('status', ['received', 'partial'])
            ->where('due_total', '>', 0)
            ->sum('due_total');

        return view('suppliers.index', [
            'title' => __('Suppliers'),
            'suppliers' => $suppliers,
            'filters' => $filters,
            'stats' => [
                'total' => Supplier::count(),
                'active' => Supplier::where('is_active', true)->count(),
                'with_dues' => Supplier::whereHas('purchases', fn ($q) => $q->whereIn('status', ['received', 'partial'])->where('due_total', '>', 0))->count(),
                'payables' => Money::add($payables, '0', 2),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->supplierData($request);

        $supplier = DB::transaction(function () use ($data, $request) {
            $supplier = Supplier::create($data);

            AuditLogger::log(
                'created',
                Supplier::class,
                $supplier->id,
                null,
                $supplier->toArray(),
                'Supplier created: ' . $supplier->name,
                $request->user()->branch_id
            );

            return $supplier;
        });

        return redirect()
            ->route('suppliers.index')
            ->with('success', __('Supplier ":name" has been created.', ['name' => $supplier->name]));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->supplierData($request, $supplier);
        $original = $supplier->toArray();

        DB::transaction(function () use ($supplier, $data, $original, $request) {
            $supplier->update($data);

            AuditLogger::log(
                'updated',
                Supplier::class,
                $supplier->id,
                $original,
                $supplier->toArray(),
                'Supplier updated: ' . $supplier->name,
                $request->user()->branch_id
            );
        });

        return redirect()
            ->route('suppliers.index')
            ->with('success', __('Supplier ":name" has been updated.', ['name' => $supplier->name]));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->purchases()->exists()) {
            return redirect()
                ->route('suppliers.index')
                ->with('error', __('Supplier ":name" has purchase history. Set them to inactive instead of deleting them.', ['name' => $supplier->name]));
        }

        $original = $supplier->toArray();

        DB::transaction(function () use ($supplier, $original) {
            AuditLogger::log(
                'deleted',
                Supplier::class,
                $supplier->id,
                $original,
                null,
                'Supplier deleted: ' . $supplier->name
            );

            $supplier->delete();
        });

        return redirect()
            ->route('suppliers.index')
            ->with('success', __('Supplier ":name" has been deleted.', ['name' => $supplier->name]));
    }

    protected function supplierData(Request $request, ?Supplier $supplier = null): array
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:32', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => __('supplier name'),
            'tax_number' => __('tax number'),
            'opening_balance' => __('opening balance'),
            'is_active' => __('status'),
        ]);

        return [
            'code' => $validated['code'] ?? null,
            'name' => $validated['name'],
            'company' => $validated['company'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'opening_balance' => Number::money((string) $request->input('opening_balance', '0')),
            'notes' => $validated['notes'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}

