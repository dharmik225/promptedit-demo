<?php

use App\Http\Controllers\Admin\GenerationController as AdminGenerationController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationPricingEstimateController;
use App\Http\Middleware\RedirectAdminToAnalytics;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(RedirectAdminToAnalytics::class)->group(function () {
        Route::get('dashboard', [GenerationController::class, 'create'])->name('dashboard');

        Route::get('generations/pricing-estimate', GenerationPricingEstimateController::class)
            ->middleware('throttle:120,1')
            ->name('generations.pricing_estimate');

        Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

        Route::post('generations', [GenerationController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('generations.store');
    });

    Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');

    Route::get('admin/generations', [AdminGenerationController::class, 'index'])
        ->middleware('can:viewAdmin')
        ->name('admin.generations.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
