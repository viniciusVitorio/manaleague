<?php

use App\Http\Controllers\LifeCounterController;
use Illuminate\Support\Facades\Route;

// Contador de vida (estilo Lotus) vinculado a uma partida; o resultado sai pelos endpoints canonicos.
Route::middleware('auth')->group(function (): void {
    Route::get('/tournaments/{tournament}/matches/{match}/life', [LifeCounterController::class, 'tournament'])->name('life.tournament');
    Route::get('/challenges/{challenge}/life', [LifeCounterController::class, 'challenge'])->name('life.challenge');
    if (config('ranked.enabled')) {
        Route::get('/ranked/matches/{match}/life', [LifeCounterController::class, 'ranked'])->name('life.ranked');
    }
});
