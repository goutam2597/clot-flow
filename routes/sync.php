<?php

use Illuminate\Support\Facades\Route;

/*
| Offline synchronization management.
| The POS batches pending offline transactions and posts them here with
| idempotency keys. Each transaction is processed independently so one failure
| does not block the rest.
*/
Route::middleware('permission:pos.access')->prefix('sync')->name('sync.')->group(function () {
    Route::get('/status', [\App\Http\Controllers\SyncController::class, 'status'])->name('status');

    // Ingest a batch of sales captured while this terminal was disconnected.
    Route::post('/sales', [\App\Http\Controllers\SyncController::class, 'receive'])->name('receive');

    Route::post('/push', [\App\Http\Controllers\SyncController::class, 'push'])->name('push');
    Route::get('/log', [\App\Http\Controllers\SyncController::class, 'log'])->name('log');
});
