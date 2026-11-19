<?php

use App\Http\Controllers\Admin\SpendReportController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationCatchUpController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generate')
        ->name('generations.store');
    Route::post('generations/estimate', GenerationEstimateController::class)
        ->middleware('throttle:estimate')
        ->name('generations.estimate');
    Route::post('generations/catch-up', GenerationCatchUpController::class)
        ->middleware('throttle:catch-up')
        ->name('generations.catch-up');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits/purchases', [CreditController::class, 'purchase'])
        ->middleware('throttle:purchase')
        ->name('credits.purchase');

    Route::middleware('can:viewAdminReports')->prefix('admin')->name('admin.')->group(function () {
        Route::get('reports/spend', SpendReportController::class)->name('reports.spend');
        Route::get('system', SystemStatusController::class)->name('system');
    });
});

require __DIR__.'/settings.php';
