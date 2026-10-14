<?php

use Illuminate\Support\Facades\Route;

/*
| Purchases, expenses, invoices, accounting, reports.
*/

// Purchases
Route::middleware('permission:purchases.view')->group(function () {
    Route::get('/purchases', [\App\Http\Controllers\PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/create', [\App\Http\Controllers\PurchaseController::class, 'create'])->name('purchases.create');
    Route::get('/purchases/{purchase}', [\App\Http\Controllers\PurchaseController::class, 'show'])->name('purchases.show');
    Route::post('/purchases', [\App\Http\Controllers\PurchaseController::class, 'store'])
        ->middleware('permission:purchases.create')->name('purchases.store');
    Route::post('/purchases/{purchase}/receive', [\App\Http\Controllers\PurchaseController::class, 'receive'])
        ->middleware('permission:purchases.receive')->name('purchases.receive');
});

// Expenses
Route::middleware('permission:expenses.view')->group(function () {
    Route::get('/expenses', [\App\Http\Controllers\ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/categories', [\App\Http\Controllers\ExpenseController::class, 'categories'])->name('expenses.categories');
    Route::post('/expenses', [\App\Http\Controllers\ExpenseController::class, 'store'])
        ->middleware('permission:expenses.manage')->name('expenses.store');
    Route::post('/expenses/categories', [\App\Http\Controllers\ExpenseController::class, 'storeCategory'])
        ->middleware('permission:expenses.manage')->name('expenses.categories.store');
});

// Invoices
Route::middleware('permission:invoices.view')->group(function () {
    Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{sale}', [\App\Http\Controllers\InvoiceController::class, 'show'])->name('invoices.show');
});

// Accounting
Route::middleware('permission:accounting.view')->group(function () {
    Route::get('/accounting', [\App\Http\Controllers\AccountingController::class, 'index'])->name('accounting.index');
    Route::get('/accounting/accounts', [\App\Http\Controllers\AccountingController::class, 'accounts'])->name('accounting.accounts');
    Route::get('/accounting/journals', [\App\Http\Controllers\AccountingController::class, 'journals'])->name('accounting.journals');
    Route::get('/accounting/journals/{journalEntry}', [\App\Http\Controllers\AccountingController::class, 'show'])->name('accounting.journals.show');
    Route::get('/accounting/trial-balance', [\App\Http\Controllers\AccountingController::class, 'trialBalance'])->name('accounting.trial-balance');
    Route::get('/accounting/general-ledger', [\App\Http\Controllers\AccountingController::class, 'generalLedger'])->name('accounting.general-ledger');
    Route::get('/accounting/profit-loss', [\App\Http\Controllers\AccountingController::class, 'profitLoss'])->name('accounting.profit-loss');
    Route::get('/accounting/balance-sheet', [\App\Http\Controllers\AccountingController::class, 'balanceSheet'])->name('accounting.balance-sheet');
    Route::post('/accounting/journals/{journalEntry}/reverse', [\App\Http\Controllers\AccountingController::class, 'reverse'])
        ->middleware('permission:accounting.manage')->name('accounting.reverse');
});

// Reports
Route::middleware('permission:reports.view')->group(function () {
    Route::get('/reports', [\App\Http\Controllers\ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/profit-loss', [\App\Http\Controllers\ReportController::class, 'profitLoss'])->name('reports.profit-loss');
    Route::get('/reports/sales', [\App\Http\Controllers\ReportController::class, 'sales'])->name('reports.sales');
    Route::get('/reports/inventory', [\App\Http\Controllers\ReportController::class, 'inventory'])->name('reports.inventory');
});
