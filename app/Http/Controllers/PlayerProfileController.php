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
        $profile = $this->ownProfile($request);
        $summary = $stats->for($profile);
        return view('players.show', compact('profile', 'summary'));
    }

    public function show(Request $request, PlayerProfile $profile, PlayerStatsService $stats): View
    {
        $isOwner = $profile->account_user_id === $request->user()->id;
        $isOrganizer = $profile->entries()->whereHas('tournament', fn ($query) => $query->where('user_id', $request->user()->id))->exists();
        abort_unless($isOwner || $isOrganizer, 403);
        $summary = $stats->for($profile);
        return view('players.show', compact('profile', 'summary'));
    }

    public function edit(Request $request): View
    {
        $profile = $this->ownProfile($request)->load('decks');
        return view('players.edit', compact('profile'));
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = $this->ownProfile($request);
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:80'],
            'avatar' => ['nullable', 'string', 'max:20'],
        ]);
        $profile->update($validated);
        return redirect()->route('profiles.index')->with('status', 'Perfil atualizado.');
    }

    public function toggleFriend(Request $request, PlayerProfile $profile): RedirectResponse
    {
        abort_if($profile->account_user_id === $request->user()->id, 422, 'Você não pode adicionar a si mesmo.');
        $knownPlayer = $profile->entries()->whereHas('tournament', fn ($query) => $query->where('user_id', $request->user()->id))->exists();
        abort_unless($knownPlayer, 403);
        $attached = ! $request->user()->friendProfiles()->whereKey($profile->id)->exists();
        $request->user()->friendProfiles()->toggle($profile->id);
        return back()->with('status', $attached ? 'Amigo salvo.' : 'Removido dos amigos.');
    }

    private function ownProfile(Request $request): PlayerProfile
    {
        return $request->user()->ensurePlayerProfile();
    }
}
