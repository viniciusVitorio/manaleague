<?php
use App\Http\Controllers\TournamentFlowController;
use Illuminate\Support\Facades\Route;
Route::post('/tournaments/{tournament}/advance', [TournamentFlowController::class, 'advance'])
    ->middleware(['auth','throttle:30,1'])->name('tournaments.advance');

// Dia de evento: cronômetro da rodada e check-in.
Route::middleware(['auth', 'throttle:30,1'])->group(function (): void {
    Route::post('/tournaments/{tournament}/timer', [\App\Http\Controllers\EventDayController::class, 'startTimer'])->name('tournaments.timer.start');
    Route::delete('/tournaments/{tournament}/timer', [\App\Http\Controllers\EventDayController::class, 'stopTimer'])->name('tournaments.timer.stop');
    Route::post('/tournaments/{tournament}/check-in', [\App\Http\Controllers\EventDayController::class, 'checkIn'])->name('tournaments.check-in');
    Route::patch('/tournaments/{tournament}/players/{player}/check-in', [\App\Http\Controllers\EventDayController::class, 'togglePlayer'])->name('tournaments.players.check-in');
    Route::delete('/tournaments/{tournament}/absent', [\App\Http\Controllers\EventDayController::class, 'removeAbsent'])->name('tournaments.absent.destroy');
});
