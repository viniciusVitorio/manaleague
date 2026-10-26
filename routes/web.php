<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeckCardController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\FriendRequestController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\PublicArenaController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TournamentEntryController;
use App\Http\Controllers\TournamentFlowController;
use App\Http\Controllers\TournamentFriendController;
use App\Http\Controllers\TournamentInviteController;
use Illuminate\Support\Facades\Route;

Route::pattern('code', '[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}');

// Each module declares its own auth/public middleware. Static routes load before model bindings.
foreach (['competition', 'live', 'deck-tools', 'community', 'challenges', 'ranked', 'life'] as $module) {
    $moduleRoutes = __DIR__."/{$module}.php";
    if (is_file($moduleRoutes)) {
        require $moduleRoutes;
    }
}

Route::view('/', 'welcome')->name('home');
Route::get('/t/{slug}', PublicArenaController::class)->middleware('throttle:60,1')->name('arena.public');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

    // Recuperacao de senha: resposta generica (sem enumeracao) + throttle por IP e cooldown por e-mail no broker.
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/join/{token}', [TournamentInviteController::class, 'show'])->name('tournaments.invite.show');
    Route::post('/join/{token}', [TournamentInviteController::class, 'store'])->middleware('throttle:10,1')->name('tournaments.invite.store');

    Route::get('/me/profile', [PlayerProfileController::class, 'index'])->name('profiles.index');
    Route::get('/me/profile/edit', [PlayerProfileController::class, 'edit'])->name('profiles.edit');
    Route::patch('/me/profile', [PlayerProfileController::class, 'update'])->name('profiles.update');
    Route::put('/me/password', [PasswordController::class, 'update'])->middleware('throttle:5,1')->name('password.change');
    Route::patch('/me/preferences', [\App\Http\Controllers\PreferencesController::class, 'update'])->name('preferences.update');
    Route::patch('/me/preferences/theme', [\App\Http\Controllers\PreferencesController::class, 'toggleTheme'])->name('preferences.theme');

    Route::get('/me/friends', [FriendController::class, 'index'])->middleware('throttle:60,1')->name('friends.index');
    Route::patch('/me/friends/privacy', [FriendController::class, 'privacy'])->name('friends.privacy');
    Route::post('/me/friends/{code}', [FriendController::class, 'store'])->middleware('throttle:20,1')->name('friends.store');
    Route::patch('/me/friends/{code}', [FriendController::class, 'accept'])->name('friends.accept-code');
    Route::delete('/me/friends/{code}', [FriendController::class, 'destroy'])->name('friends.destroy');

    Route::get('/cards/search', [DeckCardController::class, 'search'])->middleware('throttle:40,1')->name('cards.search');
    Route::post('/me/decks', [DeckController::class, 'store'])->name('decks.store');
    Route::get('/decks/{deck}', [DeckController::class, 'show'])->name('decks.show');
    Route::get('/me/decks/{deck}/edit', [DeckController::class, 'edit'])->name('decks.edit');
    Route::post('/me/decks/{deck}/cards', [DeckCardController::class, 'store'])->middleware('throttle:60,1')->name('decks.cards.store');
    Route::patch('/me/decks/{deck}/cards/{card}', [DeckCardController::class, 'update'])->name('decks.cards.update');
    Route::delete('/me/decks/{deck}/cards/{card}', [DeckCardController::class, 'destroy'])->name('decks.cards.destroy');
    Route::post('/me/decks/{deck}/import', [DeckCardController::class, 'import'])->middleware('throttle:10,1')->name('decks.cards.import');
    Route::patch('/me/decks/{deck}', [DeckController::class, 'update'])->name('decks.update');
    Route::patch('/me/decks/{deck}/cover', [DeckController::class, 'cover'])->name('decks.cover');
    Route::patch('/me/decks/{deck}/primary', [DeckController::class, 'primary'])->name('decks.primary');
    Route::delete('/me/decks/{deck}', [DeckController::class, 'destroy'])->name('decks.destroy');
    Route::get('/players/{profile}', [PlayerProfileController::class, 'show'])->name('profiles.show');
    Route::patch('/players/{profile}/friend', [PlayerProfileController::class, 'toggleFriend'])->name('profiles.friend');
    Route::post('/players/{profile}/builder', [PlayerProfileController::class, 'builder'])->name('profiles.builder');
    Route::patch('/me/friend-requests/{requester}/accept', [FriendRequestController::class, 'accept'])->name('friends.accept');
    Route::delete('/me/friend-requests/{requester}', [FriendRequestController::class, 'reject'])->name('friends.reject');

    Route::resource('tournaments', TournamentController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::post('/tournaments/{tournament}/invite/regenerate', [TournamentController::class, 'regenerateInvite'])->name('tournaments.invite.regenerate');
    Route::delete('/tournaments/{tournament}/players/{player}', [PlayerController::class, 'destroy'])->name('players.destroy');
    Route::post('/tournaments/{tournament}/friends/{profile}', [TournamentFriendController::class, 'store'])->name('tournaments.friends.store');
    Route::put('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'update'])->name('matches.update');
    Route::patch('/tournaments/{tournament}/my-entry', [TournamentEntryController::class, 'update'])->middleware('throttle:20,1')->name('tournaments.entry.update');
    Route::post('/tournaments/{tournament}/start', [TournamentFlowController::class, 'start'])->name('tournaments.start');
    Route::post('/tournaments/{tournament}/finals', [TournamentFlowController::class, 'finals'])->name('tournaments.finals');
});
