<?php
use App\Http\Controllers\MyPrizesController;
use App\Http\Controllers\TournamentFlowController;
use App\Http\Controllers\TournamentPrizeController;
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

// Premiação: o organizador define por colocação e marca a entrega dos boosters; o jogador vê o que ganhou.
Route::middleware(['auth', 'throttle:30,1'])->group(function (): void {
    Route::get('/tournaments/{tournament}/prizes', [TournamentPrizeController::class, 'edit'])->name('tournaments.prizes.edit');
    Route::put('/tournaments/{tournament}/prizes', [TournamentPrizeController::class, 'update'])->name('tournaments.prizes.update');
    Route::patch('/tournaments/{tournament}/prizes/awards/{award}', [TournamentPrizeController::class, 'deliver'])->name('tournaments.prizes.deliver');
    Route::get('/me/prizes', [MyPrizesController::class, 'index'])->name('prizes.index');
    Route::patch('/me/prizes/{award}/redeem', [MyPrizesController::class, 'redeem'])->whereNumber('award')->name('prizes.redeem');
    Route::patch('/me/prizes/{award}/showcase', [MyPrizesController::class, 'showcase'])->whereNumber('award')->name('prizes.showcase');
});
