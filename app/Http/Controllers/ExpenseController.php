<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\AccountingService;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\NumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Expenses and expense categories.
 *
 * Recording an expense always posts a balanced journal entry through the
 * AccountingService (debit expense account / credit cash) inside the same
 * transaction that persists the expense record.
 */
class ExpenseController extends Controller
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public function __construct(protected AccountingService $accounting) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->only(['search', 'category_id', 'payment_method_id', 'from', 'to']);

        $query = Expense::query()
            ->with(['category', 'branch', 'paymentMethod', 'account', 'user'])
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('reference', 'like', '%' . $term . '%')
                        ->orWhere('payee', 'like', '%' . $term . '%')
                        ->orWhere('description', 'like', '%' . $term . '%');
                });
            })
            ->when($filters['category_id'] ?? null, fn ($q, $value) => $q->where('expense_category_id', $value))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $value) => $q->where('payment_method_id', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('expense_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('expense_date', '<=', $value));

        $totals = [
            'count' => (clone $query)->count(),
            'amount' => (string) (clone $query)->sum('amount'),
            'tax' => (string) (clone $query)->sum('tax_amount'),
        ];

        $monthTotal = (string) Expense::query()
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('amount');

        $expenses = $query->latest('expense_date')->paginate(20)->withQueryString();

        return view('expenses.index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'totals' => $totals,
            'monthTotal' => $monthTotal,
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::where('is_active', true)->orderBy('sort_order')->get(),
            'accounts' => Account::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'payee' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:191'],
        ], [], [
            'expense_category_id' => __('category'),
            'payment_method_id' => __('payment method'),
            'account_id' => __('expense account'),
            'amount' => __('amount'),
            'tax_amount' => __('tax'),
            'expense_date' => __('expense date'),
        ]);

        $amount = $this->amount($validated['amount']);
        $tax = $this->amount($validated['tax_amount'] ?? '0');
        $branchId = $validated['branch_id'] ?? $request->user()->branch_id;

        try {
            $expense = DB::transaction(function () use ($validated, $amount, $tax, $branchId) {
                $expense = Expense::create([
                    'reference' => NumberGenerator::next('EXP'),
                    'expense_category_id' => $validated['expense_category_id'] ?? null,
                    'branch_id' => $branchId,
                    'user_id' => auth()->id(),
                    'payment_method_id' => $validated['payment_method_id'] ?? null,
                    'account_id' => $validated['account_id'] ?? null,
                    'amount' => $amount,
                    'tax_amount' => $tax,
                    'expense_date' => $validated['expense_date'],
                    'payee' => $validated['payee'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'status' => 'approved',
                ]);

                // Posts Dr expense account / Cr cash as a balanced journal entry.
                $this->accounting->postExpenseEntry($expense);

                return $expense;
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('The expense could not be saved. Please try again.'));
        }

        AuditLogger::log(
            'expense.created',
            Expense::class,
            $expense->id,
            null,
            [
                'reference' => $expense->reference,
                'expense_category_id' => $expense->expense_category_id,
                'amount' => (string) $expense->amount,
                'tax_amount' => (string) $expense->tax_amount,
                'expense_date' => $expense->expense_date->toDateString(),
                'payee' => $expense->payee,
            ],
            __('Expense :reference recorded', ['reference' => $expense->reference]),
        );

        return redirect()
            ->route('expenses.index')
            ->with('success', __('Expense :reference recorded and posted to the ledger.', ['reference' => $expense->reference]));
    }

    public function categories(Request $request): View
    {
        $categories = ExpenseCategory::query()
            ->withCount('expenses')
            ->withSum('expenses', 'amount')
            ->orderBy('name')
            ->paginate(20);

        return view('expenses.categories', [
            'categories' => $categories,
            'totalExpenses' => (string) Expense::sum('amount'),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:expense_categories,name'],
            'code' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = ExpenseCategory::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditLogger::log(
            'expense_category.created',
            ExpenseCategory::class,
            $category->id,
            null,
            ['name' => $category->name, 'code' => $category->code],
            __('Expense category :name created', ['name' => $category->name]),
        );

        return redirect()
            ->route('expenses.categories')
            ->with('success', __('Expense category :name created.', ['name' => $category->name]));
    }

    /** Normalise a posted amount into a clean decimal string. */
    protected function amount($value, int $scale = 2): string
    {
        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            $value = '0';
        }

        return bcadd($value, '0', $scale);
    }
}
