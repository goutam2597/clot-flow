<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $branchId = $request->user()->branch_id;

        // Today vs month sales (completed only).
        $salesQuery = Sale::completed();
        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
        }

        $today = (clone $salesQuery)->whereDate('sold_at', today());
        $month = (clone $salesQuery)->whereBetween('sold_at', [now()->startOfMonth(), now()->endOfMonth()]);

        $todaySales = (clone $today);
        $todaySalesTotal = $todaySales->sum('grand_total');
        $todayCount = (clone $today)->count();

        $monthSalesTotal = (clone $month)->sum('grand_total');
        $monthCount = (clone $month)->count();

        // Gross profit today: sum(subtotal) - sum(cost_total).
        $todayGross = Money::sub(
            (string) (clone $today)->sum('subtotal'),
            (string) (clone $today)->sum('cost_total'),
            2
        );

        // Outstanding receivables (customer dues on completed sales).
        $receivableQuery = Sale::completed()->where('due_total', '>', 0);
        if ($branchId) {
            $receivableQuery->where('branch_id', $branchId);
        }
        $receivables = (string) (clone $receivableQuery)->sum('due_total');

        // Outstanding payables.
        $payableQuery = Purchase::whereIn('status', ['received', 'partial'])->where('due_total', '>', 0);
        if ($branchId) {
            $payableQuery->where('branch_id', $branchId);
        }
        $payables = (string) (clone $payableQuery)->sum('due_total');

        // Low stock products (below reorder level) across accessible warehouses.
        $warehouseIds = $branchId
            ? Warehouse::where('branch_id', $branchId)->pluck('id')
            : Warehouse::pluck('id');

        $lowStock = Stock::whereIn('warehouse_id', $warehouseIds)
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->whereColumn('stocks.quantity', '<=', 'products.reorder_level')
            ->where('products.track_stock', true)
            ->select('stocks.*')
            ->with('product')
            ->orderBy('stocks.quantity')
            ->limit(8)
            ->get();

        // Recent sales.
        $recentSales = (clone $salesQuery)->with(['customer', 'user'])
            ->latest('sold_at')
            ->limit(8)
            ->get();

        // Top selling products this month.
        $topProducts = \App\Models\SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->whereBetween('sales.sold_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('sale_items.product_name as name, SUM(sale_items.quantity) as qty, SUM(sale_items.line_total) as total')
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        // 7-day sales trend.
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $dayTotal = (clone $salesQuery)->whereDate('sold_at', $date)->sum('grand_total');
            $trend[] = [
                'label' => $date->format('D'),
                'date' => $date->format('M j'),
                'total' => (float) $dayTotal,
            ];
        }

        // Payment breakdown today.
        $paymentBreakdown = \App\Models\SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', 'completed')
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->whereDate('sales.sold_at', today())
            ->selectRaw('sale_payments.payment_method_id, SUM(sale_payments.amount) as total')
            ->groupBy('sale_payments.payment_method_id')
            ->with('paymentMethod')
            ->get();

        return view('dashboard.index', [
            'stats' => [
                'today_sales' => $todaySalesTotal,
                'today_count' => $todayCount,
                'month_sales' => $monthSalesTotal,
                'month_count' => $monthCount,
                'today_gross' => $todayGross,
                'receivables' => $receivables,
                'payables' => $payables,
            ],
            'lowStock' => $lowStock,
            'recentSales' => $recentSales,
            'topProducts' => $topProducts,
            'trend' => $trend,
            'paymentBreakdown' => $paymentBreakdown,
            'customerCount' => Customer::when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count(),
            'productCount' => Product::where('status', 'active')->count(),
        ]);
    }
}
