<?php

namespace App\Http\Controllers;

use App\Models\PlayerProfile;
use App\Services\PlayerStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerProfileController extends Controller
{
    public function index(Request $request, PlayerStatsService $stats): View
    {
        $profiles = $request->user()->playerProfiles()->orderByDesc('is_friend')->orderBy('nickname')->get()
            ->map(fn (PlayerProfile $profile) => ['profile' => $profile, 'stats' => $stats->for($profile)]);
        return view('players.index', compact('profiles'));
    }

    public function show(Request $request, PlayerProfile $profile, PlayerStatsService $stats): View
    {
        abort_unless($profile->user_id === $request->user()->id, 403);
        $summary = $stats->for($profile);
        return view('players.show', compact('profile', 'summary'));
    }

    public function toggleFriend(Request $request, PlayerProfile $profile): RedirectResponse
    {
        abort_unless($profile->user_id === $request->user()->id, 403);
        $profile->update(['is_friend' => ! $profile->is_friend]);
        return back()->with('status', $profile->is_friend ? 'Amigo salvo.' : 'Removido dos amigos.');
    }
}
