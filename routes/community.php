<?php

use App\Http\Controllers\Community\ClubController;
use App\Http\Controllers\Community\CommunityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [CommunityController::class, 'dashboard'])->name('dashboard');
    Route::get('/feed', [CommunityController::class, 'feed'])->name('feed.index');
    Route::get('/notifications', [CommunityController::class, 'notifications'])->name('notifications.index');
    Route::patch('/notifications/preferences', [CommunityController::class, 'preferences'])->name('notifications.preferences');
    Route::patch('/notifications/read-all', [CommunityController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [CommunityController::class, 'read'])->name('notifications.read');
    Route::get('/clubs', [ClubController::class, 'index'])->name('clubs.index');
    Route::post('/clubs', [ClubController::class, 'store'])->middleware('throttle:10,1')->name('clubs.store');
    Route::get('/clubs/invite/{token}', [ClubController::class, 'invite'])->middleware('throttle:30,1')->name('clubs.invite');
    Route::post('/clubs/invite/{token}', [ClubController::class, 'acceptInvite'])->middleware('throttle:10,1')->name('clubs.invite.accept');
    Route::get('/clubs/{club}', [ClubController::class, 'show'])->name('clubs.show');
    Route::post('/clubs/{club}/invite', [ClubController::class, 'regenerateInvite'])->name('clubs.invite.regenerate');
    Route::post('/clubs/{club}/posts', [ClubController::class, 'post'])->middleware('throttle:20,1')->name('clubs.posts.store');
    Route::delete('/clubs/{club}/posts/{post}', [ClubController::class, 'deletePost'])->name('clubs.posts.destroy');
    Route::patch('/clubs/{club}', [ClubController::class, 'update'])->name('clubs.update');
    Route::post('/clubs/{club}/join', [ClubController::class, 'join'])->name('clubs.join');
    Route::post('/clubs/{club}/members', [ClubController::class, 'addMember'])->middleware('throttle:20,1')->name('clubs.members.store');
    Route::patch('/clubs/{club}/members/{user}', [ClubController::class, 'role'])->name('clubs.members.role');
    Route::delete('/clubs/{club}/members/{user}', [ClubController::class, 'remove'])->name('clubs.members.remove');
    Route::post('/clubs/{club}/tournaments', [ClubController::class, 'tournament'])->name('clubs.tournaments.store');
    Route::post('/clubs/{club}/tournaments/link', [ClubController::class, 'linkTournament'])->name('clubs.tournaments.link');
    Route::delete('/clubs/{club}/tournaments/{tournament}', [ClubController::class, 'unlinkTournament'])->name('clubs.tournaments.unlink');
});
