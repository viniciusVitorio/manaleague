<?php
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\AchievementShareController;
use Illuminate\Support\Facades\Route;

Route::get('/s/achievement/{token}', [AchievementShareController::class, 'publicShow'])->where('token', '[a-f0-9]{64}')->middleware('throttle:60,1')->name('achievements.public');
Route::middleware('auth')->group(function () {
    Route::get('/challenges', [ChallengeController::class, 'index'])->name('challenges.index');
    Route::get('/challenges/rivalries', [ChallengeController::class, 'rivalries'])->name('challenges.rivalries');
    Route::post('/challenges', [ChallengeController::class, 'store'])->middleware('throttle:10,1')->name('challenges.store');
    Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');
    foreach (['transition', 'report', 'review'] as $action) Route::post('/challenges/{challenge}/'.$action, [ChallengeController::class, $action])->middleware('throttle:30,1')->name('challenges.'.$action);
    Route::get('/achievements/{key}/share', [AchievementShareController::class, 'show'])->name('achievements.share');
    Route::post('/achievements/{key}/share', [AchievementShareController::class, 'store'])->middleware('throttle:10,1')->name('achievements.share.store');
    Route::delete('/achievements/{key}/share', [AchievementShareController::class, 'destroy'])->name('achievements.share.destroy');
});
