<?php

use Illuminate\Support\Facades\Route;

/*
| Catalog: products, categories, inventory
*/
Route::middleware('permission:products.view')->group(function () {
    Route::get('/products', [\App\Http\Controllers\ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [\App\Http\Controllers\ProductController::class, 'store'])->middleware('permission:products.manage')->name('products.store');
    Route::patch('/products/{product}', [\App\Http\Controllers\ProductController::class, 'update'])->middleware('permission:products.manage')->name('products.update');
    Route::delete('/products/{product}', [\App\Http\Controllers\ProductController::class, 'destroy'])->middleware('permission:products.manage')->name('products.destroy');

    Route::get('/categories', [\App\Http\Controllers\CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [\App\Http\Controllers\CategoryController::class, 'store'])->name('categories.store');
    Route::patch('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'destroy'])->name('categories.destroy');
});

/*
| Bulk actions.
|
| Deliberately outside the per-resource permission groups: one endpoint serves
| products, categories, customers, suppliers and users, and BulkActionService
| gates each against its own permission. Left inside `products.view`, a cashier
| who can manage customers but not products could never bulk-delete a customer.
*/
Route::middleware('auth')->post('/bulk/{resource}', \App\Http\Controllers\BulkDestroyController::class)
    ->name('bulk.destroy');

Route::middleware('permission:inventory.view')->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index'])->name('index');
    Route::post('/adjust', [\App\Http\Controllers\InventoryController::class, 'adjust'])->name('adjust');
    Route::get('/transfers', [\App\Http\Controllers\InventoryController::class, 'transfers'])->name('transfers');
    Route::post('/transfers', [\App\Http\Controllers\InventoryController::class, 'storeTransfer'])->name('transfers.store');
});

Route::middleware('permission:customers.view')->group(function () {
    Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [\App\Http\Controllers\CustomerController::class, 'store'])->middleware('permission:customers.manage')->name('customers.store');
    Route::patch('/customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'update'])->middleware('permission:customers.manage')->name('customers.update');
    Route::delete('/customers/{customer}', [\App\Http\Controllers\CustomerController::class, 'destroy'])->middleware('permission:customers.manage')->name('customers.destroy');
});

Route::middleware('permission:suppliers.view')->group(function () {
    Route::get('/suppliers', [\App\Http\Controllers\SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [\App\Http\Controllers\SupplierController::class, 'store'])->middleware('permission:suppliers.manage')->name('suppliers.store');
    Route::patch('/suppliers/{supplier}', [\App\Http\Controllers\SupplierController::class, 'update'])->middleware('permission:suppliers.manage')->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [\App\Http\Controllers\SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage')->name('suppliers.destroy');
});
