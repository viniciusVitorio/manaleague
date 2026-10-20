<?php
use App\Http\Controllers\TournamentFlowController;
use Illuminate\Support\Facades\Route;
Route::post('/tournaments/{tournament}/advance', [TournamentFlowController::class, 'advance'])
    ->middleware(['auth','throttle:30,1'])->name('tournaments.advance');
