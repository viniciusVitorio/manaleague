<?php

use App\Http\Controllers\Ranked\LeaderboardController;
use App\Http\Controllers\Ranked\MatchmakingController;
use App\Http\Controllers\Ranked\PlayerRankedController;
use App\Http\Controllers\Ranked\RankedAdminController;
use App\Http\Controllers\Ranked\RankedDashboardController;
use App\Http\Controllers\Ranked\RankedMatchController;
use App\Http\Controllers\Ranked\SeasonController;
use Illuminate\Support\Facades\Route;

if (! config('ranked.enabled')) {
    return;
}

Route::middleware('auth')->group(function (): void {
    Route::get('/ranked', [RankedDashboardController::class, 'index'])->name('ranked.index');
    Route::get('/ranked/regulamento', [RankedDashboardController::class, 'rules'])->name('ranked.rules');
    Route::patch('/ranked/privacy', [RankedDashboardController::class, 'privacy'])->name('ranked.privacy');
    Route::get('/ranked/leaderboard', [LeaderboardController::class, 'index'])->middleware('throttle:60,1')->name('ranked.leaderboard');
    Route::get('/ranked/seasons/{season:slug}', [SeasonController::class, 'show'])->name('ranked.seasons.show');

    Route::post('/ranked/queue', [MatchmakingController::class, 'join'])->middleware('throttle:10,1')->name('ranked.queue.join');
    Route::delete('/ranked/queue', [MatchmakingController::class, 'leave'])->name('ranked.queue.leave');
    Route::get('/ranked/queue/status', [MatchmakingController::class, 'status'])->middleware('throttle:30,1')->name('ranked.queue.status');
    Route::post('/ranked/queue/heartbeat', [MatchmakingController::class, 'heartbeat'])->middleware('throttle:30,1')->name('ranked.queue.heartbeat');
    Route::post('/ranked/proposals/{proposal}/accept', [MatchmakingController::class, 'accept'])->middleware('throttle:20,1')->name('ranked.proposals.accept');
    Route::post('/ranked/proposals/{proposal}/decline', [MatchmakingController::class, 'decline'])->middleware('throttle:20,1')->name('ranked.proposals.decline');

    Route::get('/ranked/matches/{match}', [RankedMatchController::class, 'show'])->name('ranked.matches.show');
    Route::post('/ranked/matches/{match}/results', [RankedMatchController::class, 'results'])->middleware('throttle:20,1')->name('ranked.matches.results');
    Route::post('/ranked/matches/{match}/confirm', [RankedMatchController::class, 'confirm'])->middleware('throttle:20,1')->name('ranked.matches.confirm');
    Route::post('/ranked/matches/{match}/dispute', [RankedMatchController::class, 'dispute'])->middleware('throttle:20,1')->name('ranked.matches.dispute');
    Route::post('/ranked/matches/{match}/cancel', [RankedMatchController::class, 'cancel'])->name('ranked.matches.cancel');
    Route::post('/ranked/matches/{match}/resolve', [RankedMatchController::class, 'resolve'])->name('ranked.matches.resolve');
    Route::post('/ranked/matches/{match}/invalidate', [RankedMatchController::class, 'invalidate'])->name('ranked.matches.invalidate');

    Route::get('/players/{profile}/ranked', [PlayerRankedController::class, 'show'])->name('ranked.players.show');

    Route::get('/ranked/admin', [RankedAdminController::class, 'index'])->name('ranked.admin');
    Route::post('/ranked/admin/seasons', [RankedAdminController::class, 'store'])->name('ranked.admin.seasons.store');
    Route::post('/ranked/admin/seasons/{season:slug}/schedule', [RankedAdminController::class, 'schedule'])->name('ranked.admin.seasons.schedule');
    Route::post('/ranked/admin/seasons/{season:slug}/close', [RankedAdminController::class, 'close'])->name('ranked.admin.seasons.close');
    Route::patch('/ranked/admin/flags/{flag}', [RankedAdminController::class, 'flag'])->name('ranked.admin.flags');
    Route::post('/ranked/admin/suspensions', [RankedAdminController::class, 'suspend'])->name('ranked.admin.suspend');
});
