<?php

use Illuminate\Support\Facades\Route;

/*
| Point of Sale (online + offline-capable)
*/
Route::middleware('permission:pos.access')->group(function () {
    Route::get('/pos', [\App\Http\Controllers\PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/search', [\App\Http\Controllers\PosController::class, 'searchProducts'])->name('pos.search');
    Route::get('/pos/product/{product}', [\App\Http\Controllers\PosController::class, 'product'])->name('pos.product');
    Route::get('/pos/customers', [\App\Http\Controllers\PosController::class, 'searchCustomers'])->name('pos.customers');
    Route::post('/pos/sale', [\App\Http\Controllers\PosController::class, 'store'])->name('pos.store');
    Route::get('/pos/sale/{sale}/receipt', [\App\Http\Controllers\PosController::class, 'receipt'])->name('pos.receipt');

    // Held (parked) sales. A hold is a snapshot, never a transaction: these
    // routes cannot deduct stock or post to the ledger, only the normal
    // /pos/sale route can do that.
    Route::get('/pos/holds', [\App\Http\Controllers\HoldController::class, 'index'])->name('pos.holds.index');
    Route::post('/pos/holds', [\App\Http\Controllers\HoldController::class, 'store'])->name('pos.holds.store');
    Route::post('/pos/holds/{heldSale}/resume', [\App\Http\Controllers\HoldController::class, 'resume'])->name('pos.holds.resume');
    Route::delete('/pos/holds/{heldSale}', [\App\Http\Controllers\HoldController::class, 'destroy'])->name('pos.holds.destroy');

    // Cash registers (tills)
    Route::get('/pos/registers', [\App\Http\Controllers\CashRegisterController::class, 'index'])->name('pos.registers.index');
    Route::post('/pos/registers', [\App\Http\Controllers\CashRegisterController::class, 'store'])->name('pos.registers.store');
    Route::patch('/pos/registers/{cashRegister}', [\App\Http\Controllers\CashRegisterController::class, 'update'])->name('pos.registers.update');
    Route::delete('/pos/registers/{cashRegister}', [\App\Http\Controllers\CashRegisterController::class, 'destroy'])->name('pos.registers.destroy');

    // Register sessions (drawer open / close)
    Route::get('/pos/sessions', [\App\Http\Controllers\CashRegisterSessionController::class, 'index'])->name('pos.sessions.index');
    Route::get('/pos/sessions/create', [\App\Http\Controllers\CashRegisterSessionController::class, 'create'])->name('pos.sessions.create');
    Route::post('/pos/sessions', [\App\Http\Controllers\CashRegisterSessionController::class, 'store'])->name('pos.sessions.store');
    Route::get('/pos/sessions/current', [\App\Http\Controllers\CashRegisterSessionController::class, 'current'])->name('pos.sessions.current');
    Route::get('/pos/sessions/{session}', [\App\Http\Controllers\CashRegisterSessionController::class, 'show'])->name('pos.sessions.show');
    Route::patch('/pos/sessions/{session}/close', [\App\Http\Controllers\CashRegisterSessionController::class, 'close'])->name('pos.sessions.close');
});
