<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FriendRequestController extends Controller
{
    public function accept(Request $request, User $requester): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        abort_unless($profile->friendedBy()->whereKey($requester->id)->wherePivot('status', 'pending')->exists(), 404);
        $profile->friendedBy()->updateExistingPivot($requester->id, ['status' => 'accepted']);
        return back()->with('status', 'Solicitação aceita.');
    }

    public function reject(Request $request, User $requester): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        abort_unless($profile->friendedBy()->whereKey($requester->id)->exists(), 404);
        $profile->friendedBy()->detach($requester->id);
        return back()->with('status', 'Solicitação recusada.');
    }
}
