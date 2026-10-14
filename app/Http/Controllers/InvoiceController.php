<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer invoices (issued from completed sales).
 *
 * Invoices are read-only documents: the sale itself is the source of truth and
 * the accounting entry is posted by the SaleService at checkout time.
 */
class InvoiceController extends Controller
{
    public const STATUSES = ['completed', 'held', 'voided', 'returned'];

    public const PAYMENT_STATUSES = ['paid', 'partial', 'due'];

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->only(['search', 'status', 'payment_status', 'from', 'to']);

        $query = Sale::query()
            ->with(['customer', 'branch', 'warehouse', 'user'])
            ->when($user->branch_id, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('invoice_number', 'like', '%' . $term . '%')
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%' . $term . '%'));
                });
            })
            ->when(in_array(($filters['status'] ?? null), self::STATUSES, true) ? $filters['status'] : null,
                fn ($q, $value) => $q->where('status', $value))
            ->when(in_array(($filters['payment_status'] ?? null), self::PAYMENT_STATUSES, true) ? $filters['payment_status'] : null,
                fn ($q, $value) => $q->where('payment_status', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('sold_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('sold_at', '<=', $value));

        $totals = [
            'count' => (clone $query)->count(),
            'grand_total' => (string) (clone $query)->sum('grand_total'),
            'paid_total' => (string) (clone $query)->sum('paid_total'),
            'due_total' => (string) (clone $query)->sum('due_total'),
        ];

        $invoices = $query->latest('sold_at')->paginate(20)->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'totals' => $totals,
            'statuses' => self::STATUSES,
            'paymentStatuses' => self::PAYMENT_STATUSES,
        ]);
    }

    public function show(Sale $sale): View
    {
        $sale->load(['items.product', 'payments.paymentMethod', 'customer', 'branch', 'warehouse', 'user']);

        return view('invoices.show', [
            'sale' => $sale,
        ]);
    }
}
