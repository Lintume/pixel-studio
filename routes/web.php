<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/studio')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('studio', [StudioController::class, 'index'])->name('studio');
    Route::get('studio/estimate', [StudioController::class, 'estimate'])->name('studio.estimate');
    Route::post('generations', [StudioController::class, 'store'])->name('generations.store');
    Route::post('credits', [CreditsController::class, 'store'])->name('credits.store');
    Route::get('admin/report', AdminReportController::class)->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
