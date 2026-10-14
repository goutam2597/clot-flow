<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\Number;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer master data plus the outstanding receivable balance derived from
 * completed sales.
 */
class CustomerController extends Controller
{
    protected int $perPage = 15;

    public function index(Request $request): View
    {
        $branchId = $request->user()->branch_id;

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'dues' => $request->query('dues'),
        ];

        $customers = Customer::query()
            ->withSum(['sales as outstanding_balance' => fn ($q) => $q->where('status', 'completed')], 'due_total')
            ->withCount(['sales as sales_count' => fn ($q) => $q->where('status', 'completed')])
            ->when($branchId, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $branchId)
                ->orWhereNull('branch_id')))
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('name', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('phone', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('email', 'like', '%' . $filters['q'] . '%')
                        ->orWhere('code', 'like', '%' . $filters['q'] . '%');
                });
            })
            ->when(in_array($filters['type'], ['walkin', 'regular', 'wholesale'], true), fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($filters['dues'] === 'due', fn ($query) => $query->whereHas('sales', fn ($q) => $q->where('status', 'completed')->where('due_total', '>', 0)))
            ->orderBy('name')
            ->paginate($this->perPage)
            ->withQueryString();

        $statsBase = Customer::query()
            ->when($branchId, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('branch_id', $branchId)
                ->orWhereNull('branch_id')));

        $outstanding = (string) Sale::where('status', 'completed')
            ->where('due_total', '>', 0)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('due_total');

        return view('customers.index', [
            'title' => __('Customers'),
            'customers' => $customers,
            'filters' => $filters,
            'stats' => [
                'total' => (clone $statsBase)->count(),
                'active' => (clone $statsBase)->where('is_active', true)->count(),
                'with_dues' => (clone $statsBase)->whereHas('sales', fn ($q) => $q->where('status', 'completed')->where('due_total', '>', 0))->count(),
                'outstanding' => Money::add($outstanding, '0', 2),
            ],
            'customerTypes' => [
                'walkin' => __('Walk-in'),
                'regular' => __('Regular'),
                'wholesale' => __('Wholesale'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->customerData($request);

        $customer = DB::transaction(function () use ($data, $request) {
            $customer = Customer::create($data);

            AuditLogger::log(
                'created',
                Customer::class,
                $customer->id,
                null,
                $customer->toArray(),
                'Customer created: ' . $customer->name,
                $request->user()->branch_id
            );

            return $customer;
        });

        return redirect()
            ->route('customers.index')
            ->with('success', __('Customer ":name" has been created.', ['name' => $customer->name]));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->customerData($request, $customer);
        $original = $customer->toArray();

        DB::transaction(function () use ($customer, $data, $original, $request) {
            $customer->update($data);

            AuditLogger::log(
                'updated',
                Customer::class,
                $customer->id,
                $original,
                $customer->toArray(),
                'Customer updated: ' . $customer->name,
                $request->user()->branch_id
            );
        });

        return redirect()
            ->route('customers.index')
            ->with('success', __('Customer ":name" has been updated.', ['name' => $customer->name]));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->sales()->exists()) {
            return redirect()
                ->route('customers.index')
                ->with('error', __('Customer ":name" has sales history. Set them to inactive instead of deleting them.', ['name' => $customer->name]));
        }

        $original = $customer->toArray();

        DB::transaction(function () use ($customer, $original) {
            AuditLogger::log(
                'deleted',
                Customer::class,
                $customer->id,
                $original,
                null,
                'Customer deleted: ' . $customer->name
            );

            $customer->delete();
        });

        return redirect()
            ->route('customers.index')
            ->with('success', __('Customer ":name" has been deleted.', ['name' => $customer->name]));
    }

    protected function customerData(Request $request, ?Customer $customer = null): array
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'code')->ignore($customer?->id)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'in:walkin,regular,wholesale'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'name' => __('customer name'),
            'tax_number' => __('tax number'),
            'credit_limit' => __('credit limit'),
            'opening_balance' => __('opening balance'),
            'is_active' => __('status'),
        ]);

        return [
            'branch_id' => $request->user()->branch_id,
            'code' => $validated['code'] ?? null,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'type' => $validated['type'],
            'credit_limit' => Number::money((string) $request->input('credit_limit', '0')),
            'opening_balance' => Number::money((string) $request->input('opening_balance', '0')),
            'notes' => $validated['notes'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
