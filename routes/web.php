<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\PublicArenaController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TournamentFlowController;
use App\Http\Controllers\TournamentInviteController;
use App\Http\Controllers\TournamentFriendController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('/t/{slug}', PublicArenaController::class)->name('arena.public');
Route::get('/join/{token}', [TournamentInviteController::class, 'show'])->name('tournaments.invite.show');
Route::post('/join/{token}', [TournamentInviteController::class, 'store'])->middleware('throttle:10,1')->name('tournaments.invite.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::resource('tournaments', TournamentController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::delete('/tournaments/{tournament}/players/{player}', [PlayerController::class, 'destroy'])->name('players.destroy');
    Route::get('/players', [PlayerProfileController::class, 'index'])->name('profiles.index');
    Route::get('/players/{profile}', [PlayerProfileController::class, 'show'])->name('profiles.show');
    Route::patch('/players/{profile}/friend', [PlayerProfileController::class, 'toggleFriend'])->name('profiles.friend');
    Route::post('/tournaments/{tournament}/friends/{profile}', [TournamentFriendController::class, 'store'])->name('tournaments.friends.store');
    Route::put('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'update'])->name('matches.update');
    Route::post('/tournaments/{tournament}/start', [TournamentFlowController::class, 'start'])->name('tournaments.start');
    Route::post('/tournaments/{tournament}/finals', [TournamentFlowController::class, 'finals'])->name('tournaments.finals');
});
