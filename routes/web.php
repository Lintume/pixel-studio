<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\StudioController;
use App\Http\Middleware\OperatorReportAccess;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/studio')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('studio', [StudioController::class, 'index'])->name('studio');
    Route::get('studio/estimate', [StudioController::class, 'estimate'])->name('studio.estimate');
    Route::post('generations', [StudioController::class, 'store'])->name('generations.store');
    Route::post('credits', [CreditsController::class, 'store'])->name('credits.store');
});

// Outside the auth group: a signed link opens it without an account.
Route::get('admin/report', AdminReportController::class)
    ->middleware(OperatorReportAccess::class)
    ->name('admin.report');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
