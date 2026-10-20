<?php

use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\Ranked\RankedAdminController;
use App\Http\Controllers\Ranked\RankedMatchController;
use Illuminate\Support\Facades\Route;

if (! config('ranked.enabled')) {
    return;
}

// Sem secao propria: o ranque aparece no perfil; partidas ranqueadas nascem de desafios.
Route::middleware('auth')->group(function (): void {
    Route::patch('/me/profile/ranked-privacy', [PlayerProfileController::class, 'rankedPrivacy'])->name('ranked.privacy');

    Route::get('/ranked/matches/{match}', [RankedMatchController::class, 'show'])->name('ranked.matches.show');
    Route::post('/ranked/matches/{match}/results', [RankedMatchController::class, 'results'])->middleware('throttle:20,1')->name('ranked.matches.results');
    Route::post('/ranked/matches/{match}/confirm', [RankedMatchController::class, 'confirm'])->middleware('throttle:20,1')->name('ranked.matches.confirm');
    Route::post('/ranked/matches/{match}/dispute', [RankedMatchController::class, 'dispute'])->middleware('throttle:20,1')->name('ranked.matches.dispute');
    Route::post('/ranked/matches/{match}/cancel', [RankedMatchController::class, 'cancel'])->name('ranked.matches.cancel');
    Route::post('/ranked/matches/{match}/resolve', [RankedMatchController::class, 'resolve'])->name('ranked.matches.resolve');
    Route::post('/ranked/matches/{match}/invalidate', [RankedMatchController::class, 'invalidate'])->name('ranked.matches.invalidate');

    Route::get('/ranked/admin', [RankedAdminController::class, 'index'])->name('ranked.admin');
    Route::post('/ranked/admin/seasons', [RankedAdminController::class, 'store'])->name('ranked.admin.seasons.store');
    Route::post('/ranked/admin/seasons/{season:slug}/schedule', [RankedAdminController::class, 'schedule'])->name('ranked.admin.seasons.schedule');
    Route::post('/ranked/admin/seasons/{season:slug}/close', [RankedAdminController::class, 'close'])->name('ranked.admin.seasons.close');
    Route::patch('/ranked/admin/flags/{flag}', [RankedAdminController::class, 'flag'])->name('ranked.admin.flags');
    Route::post('/ranked/admin/suspensions', [RankedAdminController::class, 'suspend'])->name('ranked.admin.suspend');
});
