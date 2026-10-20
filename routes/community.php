<?php
use App\Http\Controllers\Community\{ClubController,CommunityController};
use Illuminate\Support\Facades\Route;
Route::middleware('auth')->group(function(){
 Route::get('/dashboard',[CommunityController::class,'dashboard'])->name('dashboard');
 Route::get('/feed',[CommunityController::class,'feed'])->name('feed.index');
 Route::get('/notifications',[CommunityController::class,'notifications'])->name('notifications.index');
 Route::patch('/notifications/preferences',[CommunityController::class,'preferences'])->name('notifications.preferences');
 Route::patch('/notifications/read-all',[CommunityController::class,'readAll'])->name('notifications.read-all');
 Route::patch('/notifications/{notification}/read',[CommunityController::class,'read'])->name('notifications.read');
 Route::get('/clubs',[ClubController::class,'index'])->name('clubs.index');
 Route::post('/clubs',[ClubController::class,'store'])->middleware('throttle:10,1')->name('clubs.store');
 Route::get('/clubs/{club}',[ClubController::class,'show'])->name('clubs.show');
 Route::patch('/clubs/{club}',[ClubController::class,'update'])->name('clubs.update');
 Route::post('/clubs/{club}/join',[ClubController::class,'join'])->name('clubs.join');
 Route::post('/clubs/{club}/members',[ClubController::class,'addMember'])->middleware('throttle:20,1')->name('clubs.members.store');
 Route::patch('/clubs/{club}/members/{user}',[ClubController::class,'role'])->name('clubs.members.role');
 Route::delete('/clubs/{club}/members/{user}',[ClubController::class,'remove'])->name('clubs.members.remove');
 Route::post('/clubs/{club}/tournaments',[ClubController::class,'tournament'])->name('clubs.tournaments.store');
});
