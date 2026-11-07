<?php

use App\Http\Controllers\LifeCounterController;
use App\Http\Controllers\LifeTableController;
use Illuminate\Support\Facades\Route;

// Contador de vida (estilo Lotus) vinculado a uma partida; o resultado sai pelos endpoints canonicos.
// Vida e contadores ficam numa mesa compartilhada: os celulares dos jogadores veem o mesmo placar.
Route::middleware('auth')->group(function (): void {
    Route::get('/tournaments/{tournament}/matches/{match}/life', [LifeCounterController::class, 'tournament'])->name('life.tournament');
    Route::get('/challenges/{challenge}/life', [LifeCounterController::class, 'challenge'])->name('life.challenge');
    if (config('ranked.enabled')) {
        Route::get('/ranked/matches/{match}/life', [LifeCounterController::class, 'ranked'])->name('life.ranked');
    }

    // Sincronia das jogadas (o celular junta os toques e envia em lote).
    Route::get('/life/tables/{table}/sync', [LifeTableController::class, 'sync'])->middleware('throttle:120,1')->whereNumber('table')->name('life.tables.sync');
    Route::post('/life/tables/{table}/ops', [LifeTableController::class, 'ops'])->middleware('throttle:240,1')->whereNumber('table')->name('life.tables.ops');
});
