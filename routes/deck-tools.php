<?php

use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DeckToolsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/me/deck-history', [DeckToolsController::class, 'history'])->name('decks.history');
    Route::get('/me/deck-history/{version}', [DeckToolsController::class, 'version'])->name('decks.versions.show');
    Route::post('/decks/{deck}/versions', [DeckToolsController::class, 'snapshot'])->name('decks.versions.store');
    Route::get('/decks/compare', [DeckToolsController::class, 'compare'])->name('decks.compare');
    Route::get('/decks/{deck}/simulator', [DeckToolsController::class, 'simulator'])->name('decks.simulator');
    Route::get('/me/collection', [CollectionController::class, 'index'])->name('collection.index');
    Route::post('/me/collection', [CollectionController::class, 'store'])->middleware('throttle:10,1')->name('collection.store');
    Route::post('/me/collection/import', [CollectionController::class, 'import'])->middleware('throttle:3,1')->name('collection.import');
    Route::get('/me/collection/export', [CollectionController::class, 'export'])->middleware('throttle:10,1')->name('collection.export');
    Route::patch('/me/collection/{item}', [CollectionController::class, 'update'])->name('collection.update');
    Route::delete('/me/collection/{item}', [CollectionController::class, 'destroy'])->name('collection.destroy');
    Route::get('/me/collection/missing/{deck}', [CollectionController::class, 'missing'])->name('collection.missing');
    Route::post('/me/collection/missing/{deck}', [CollectionController::class, 'addMissing'])->middleware('throttle:10,1,collection-missing')->name('collection.missing.add');
});
