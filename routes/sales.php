<?php

use Illuminate\Support\Facades\Route;

/*
| Sales management, receipts, and returns/refunds.
*/
Route::middleware('permission:sales.view')->group(function () {
    Route::get('/sales', [\App\Http\Controllers\SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [\App\Http\Controllers\SaleController::class, 'show'])->name('sales.show');
    Route::get('/sales/{sale}/invoice', [\App\Http\Controllers\SaleController::class, 'invoice'])->name('sales.invoice');
    Route::get('/sales-returns', [\App\Http\Controllers\SaleController::class, 'returns'])->name('sales.returns');
    Route::get('/sales-returns/{saleReturn}', [\App\Http\Controllers\SaleController::class, 'returnShow'])->name('sales.returns.show');
});

Route::middleware('permission:sales.void')->group(function () {
    Route::post('/sales/{sale}/void', [\App\Http\Controllers\SaleController::class, 'void'])->name('sales.void');
});

Route::middleware('permission:sales.refund')->group(function () {
    Route::get('/sales/{sale}/return', [\App\Http\Controllers\SaleController::class, 'returnForm'])->name('sales.return.form');
    Route::post('/sales/{sale}/return', [\App\Http\Controllers\SaleController::class, 'storeReturn'])->name('sales.return.store');
});
