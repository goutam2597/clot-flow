<?php

use Illuminate\Support\Facades\Route;

/*
| Admin, users, roles, languages, settings, audit logs, branches
*/
Route::middleware('permission:settings.manage')->prefix('admin')->name('admin.')->group(function () {
    Route::resource('users', \App\Http\Controllers\UserController::class)->except(['show']);
    Route::post('users/{user}/status', [\App\Http\Controllers\UserController::class, 'toggleStatus'])->name('users.status');
    Route::resource('roles', \App\Http\Controllers\RoleController::class)->except(['show']);

    Route::get('languages', [\App\Http\Controllers\LanguageController::class, 'index'])->name('languages.index');
    Route::get('languages/{language}/translations', [\App\Http\Controllers\LanguageController::class, 'translations'])->name('languages.translations');
    Route::post('languages', [\App\Http\Controllers\LanguageController::class, 'store'])->name('languages.store');
    Route::post('languages/{language}/translations', [\App\Http\Controllers\LanguageController::class, 'saveTranslations'])->name('languages.translations.save');
    Route::patch('languages/{language}', [\App\Http\Controllers\LanguageController::class, 'update'])->name('languages.update');
    Route::post('languages/{language}/default', [\App\Http\Controllers\LanguageController::class, 'makeDefault'])->name('languages.default');
    Route::delete('languages/{language}', [\App\Http\Controllers\LanguageController::class, 'destroy'])->name('languages.destroy');

    Route::get('settings', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
    Route::post('settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/logo', [\App\Http\Controllers\SettingsController::class, 'updateLogo'])->name('settings.logo');
    Route::post('settings/logo/remove', [\App\Http\Controllers\SettingsController::class, 'removeLogo'])->name('settings.logo.remove');

    Route::get('audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index'])->name('audit.index');
});

Route::middleware('permission:branches.manage')->prefix('branches')->name('branches.')->group(function () {
    Route::get('/', [\App\Http\Controllers\BranchController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\BranchController::class, 'store'])->name('store');
    Route::patch('/{branch}', [\App\Http\Controllers\BranchController::class, 'update'])->name('update');
});
