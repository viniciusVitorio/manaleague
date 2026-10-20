<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MatchResultSubmissionController;
Route::get('/t/{slug}/live',[\App\Http\Controllers\LiveDisplayController::class,'data'])->middleware('throttle:60,1')->name('arena.live');
Route::get('/t/{slug}/tv',[\App\Http\Controllers\LiveDisplayController::class,'tv'])->middleware('throttle:60,1')->name('arena.tv');
Route::middleware(['auth','throttle:60,1'])->group(function() {
 Route::get('/tournaments/{tournament}/registration-qr.png',[\App\Http\Controllers\RegistrationQrController::class,'png'])->name('tournaments.qr.png');
 Route::get('/tournaments/{tournament}/registration-qr',[\App\Http\Controllers\RegistrationQrController::class,'show'])->name('tournaments.qr.show');
 Route::get('/tournaments/{tournament}/submissions',[MatchResultSubmissionController::class,'queue'])->name('matches.submissions.queue');
 Route::post('/match-submissions/{submission}/respond',[MatchResultSubmissionController::class,'respond'])->name('matches.submissions.respond');
 Route::post('/match-submissions/{submission}/resolve',[MatchResultSubmissionController::class,'resolve'])->name('matches.submissions.resolve');
 Route::get('/me/matches',[MatchResultSubmissionController::class,'index'])->name('matches.mine');
 Route::post('/tournaments/{tournament}/matches/{match}/submissions',[MatchResultSubmissionController::class,'store'])->middleware('throttle:20,1')->name('matches.submissions.store');
});
