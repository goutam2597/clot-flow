<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Stock;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Business reports: overview, profit & loss, sales analysis, inventory health.
 *
 * Every figure is derived live from posted transactions (completed sales,
 * received purchases, approved expenses, closing stock valuation) so reports
 * always reconcile with the ledger and the inventory register.
 */
class ReportController extends Controller
{
    public const PER_PAGE = 20;

    /**
     * Report hub with today's headline figures.
     */
    public function index(): View
    {
        $today = today()->toDateString();

        $salesToday = Sale::completed()->whereDate('sold_at', $today);
        $todayRevenue = (string) $salesToday->sum('grand_total');
        $todayCount = (clone $salesToday)->count();

        $todayProfit = '0.00';
        foreach ((clone $salesToday)->get(['subtotal', 'cost_total']) as $sale) {
            $todayProfit = Money::add(
                $todayProfit,
                Money::sub((string) $sale->subtotal, (string) $sale->cost_total, 2),
                2
            );
        }

        $lowStock = Stock::query()
            ->join('products', 'stocks.product_id', '=', 'products.id')
            ->where('products.track_stock', true)
            ->whereColumn('stocks.quantity', '<=', 'products.reorder_level')
            ->count();

        return view('reports.index', [
            'todayRevenue' => $todayRevenue,
            'todayCount' => $todayCount,
            'todayProfit' => $todayProfit,
            'lowStock' => $lowStock,
            'monthRevenue' => (string) Sale::completed()
                ->whereMonth('sold_at', today()->month)
                ->whereYear('sold_at', today()->year)
                ->sum('grand_total'),
        ]);
    }

    /**
     * Profit & loss for a date range.
     */
    public function profitLoss(Request $request): View
    {
        $filters = $this->dateFilters($request);

        $sales = Sale::completed()
            ->when($filters['from'], fn ($q, $v) => $q->whereDate('sold_at', '>=', $v))
            ->when($filters['to'], fn ($q, $v) => $q->whereDate('sold_at', '<=', $v));

        $revenue = (string) (clone $sales)->sum('subtotal');
        $tax = (string) (clone $sales)->sum('tax_total');
        $discounts = (string) (clone $sales)->sum('discount_total');
        $cogs = (string) (clone $sales)->sum('cost_total');
        $grossProfit = Money::sub($revenue, $cogs, 2);

        $expenses = \App\Models\Expense::query()
            ->where('status', 'approved')
            ->when($filters['from'], fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'], fn ($q, $v) => $q->whereDate('expense_date', '<=', $v));
        $expenseTotal = (string) $expenses->sum('amount');
        $netProfit = Money::sub($grossProfit, $expenseTotal, 2);

        return view('reports.profit-loss', [
            'filters' => $filters,
            'revenue' => $revenue,
            'tax' => $tax,
            'discounts' => $discounts,
            'cogs' => $cogs,
            'grossProfit' => $grossProfit,
            'expenseTotal' => $expenseTotal,
            'netProfit' => $netProfit,
            'saleCount' => (clone $sales)->count(),
            'margin' => Money::isZero($revenue) ? '0.00'
                : Money::div(Money::mul($netProfit, '100', 4), $revenue, 2),
            'expenseBreakdown' => (clone $expenses)
                ->selectRaw('expense_category_id, SUM(amount) as total')
                ->with('category')
                ->groupBy('expense_category_id')
                ->orderByDesc('total')
                ->get(),
        ]);
    }

    /**
     * Sales analysis: daily totals, top products, payment mix.
     */
    public function sales(Request $request): View
    {
        $filters = $this->dateFilters($request);

        $base = Sale::completed()
            ->when($filters['from'], fn ($q, $v) => $q->whereDate('sold_at', '>=', $v))
            ->when($filters['to'], fn ($q, $v) => $q->whereDate('sold_at', '<=', $v));

        $daily = (clone $base)
            ->selectRaw('DATE(sold_at) as day, COUNT(*) as invoices, SUM(grand_total) as revenue, SUM(subtotal - cost_total) as profit')
            ->groupBy('day')
            ->orderByDesc('day')
            ->paginate(self::PER_PAGE, ['*'], 'daily_page')
            ->withQueryString();

        $dateScope = function ($q) use ($filters) {
            $q->completed()
                ->when($filters['from'], fn ($qq, $v) => $qq->whereDate('sold_at', '>=', $v))
                ->when($filters['to'], fn ($qq, $v) => $qq->whereDate('sold_at', '<=', $v));
        };

        $topProducts = \App\Models\SaleItem::query()
            ->selectRaw('product_id, product_name, SUM(quantity) as qty, SUM(line_total) as revenue')
            ->whereHas('sale', $dateScope)
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $paymentMix = \App\Models\SalePayment::query()
            ->selectRaw('payment_method_id, SUM(amount) as total, COUNT(*) as payments')
            ->whereHas('sale', $dateScope)
            ->with('paymentMethod')
            ->groupBy('payment_method_id')
            ->orderByDesc('total')
            ->get();

        return view('reports.sales', [
            'filters' => $filters,
            'summary' => [
                'revenue' => (string) (clone $base)->sum('grand_total'),
                'collected' => (string) (clone $base)->sum('paid_total'),
                'due' => (string) (clone $base)->sum('due_total'),
                'count' => (clone $base)->count(),
            ],
            'daily' => $daily,
            'topProducts' => $topProducts,
            'paymentMix' => $paymentMix,
        ]);
    }

    /**
     * Inventory health: valuation, low stock, slow lines.
     */
    public function inventory(Request $request): View
    {
        $filters = $request->only(['search', 'warehouse_id']);

        $query = Stock::query()
            ->with(['product.category', 'product.unit', 'variant', 'warehouse'])
            ->join('products', 'stocks.product_id', '=', 'products.id')
            ->where('products.track_stock', true)
            ->select('stocks.*')
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->whereHas('product', fn ($p) => $p
                    ->where('name', 'like', '%' . $term . '%')
                    ->orWhere('sku', 'like', '%' . $term . '%'));
            })
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('stocks.warehouse_id', $v))
            ->orderBy('stocks.quantity');

        $valuation = '0.00';
        foreach ((clone $query)->get(['quantity', 'avg_cost']) as $row) {
            $valuation = Money::add(
                $valuation,
                Money::mul((string) $row->quantity, (string) $row->avg_cost, 4),
                2
            );
        }
        $lowCount = (clone $query)->whereColumn('stocks.quantity', '<=', 'products.reorder_level')->count();

        return view('reports.inventory', [
            'filters' => $filters,
            'stocks' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'warehouses' => \App\Models\Warehouse::where('is_active', true)->orderBy('name')->get(),
            'valuation' => $valuation,
            'lineCount' => (clone $query)->count(),
            'lowCount' => $lowCount,
        ]);
    }

    /**
     * @return array{from: ?string, to: ?string, from_raw: string, to_raw: string}
     */
    protected function dateFilters(Request $request): array
    {
        $fromRaw = (string) $request->input('from', today()->startOfMonth()->toDateString());
        $toRaw = (string) $request->input('to', today()->toDateString());

        return [
            'from' => $this->validDate($fromRaw),
            'to' => $this->validDate($toRaw),
            'from_raw' => $fromRaw,
            'to_raw' => $toRaw,
        ];
    }

    protected function validDate(string $value): ?string
    {
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
