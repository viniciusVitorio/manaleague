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
    // Gráfico da partida: página inteira, ou só o gráfico quando a janela da lista pede (X-Requested-With).
    Route::get('/life-logs/{type}/{id}', [LifeCounterController::class, 'showLog'])
        ->whereIn('type', ['tournament', 'challenge', 'ranked'])->whereNumber('id')->name('life.log.show');
    // Histórico de vida (gráfico da partida): o contador manda durante o jogo, no máximo a cada 10 s, e ao sair da tela.
    Route::post('/life-logs/{type}/{id}', [LifeCounterController::class, 'storeLog'])
        ->whereIn('type', ['tournament', 'challenge', 'ranked'])->whereNumber('id')
        ->middleware('throttle:30,1')->name('life.log.store');
});
