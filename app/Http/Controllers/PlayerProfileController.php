<?php

namespace App\Http\Controllers;

use App\Models\PlayerProfile;
use App\Models\PlayerRating;
use App\Models\RankedSeason;
use App\Models\RatingSeasonSnapshot;
use App\Models\RatingTransaction;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\FriendshipService;
use App\Services\PlayerStatsService;
use App\Services\Ranked\RankTierResolver;
use App\Services\Ranked\SeasonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerProfileController extends Controller
{
    public function __construct(
        private readonly PlayerStatsService $stats,
        private readonly AchievementService $achievements,
        private readonly FriendshipService $friendships,
    ) {}

    public function index(Request $request): View
    {
        $profile = $this->ownProfile($request);
        $summary = $this->stats->for($profile);
        $this->achievements->sync($profile, $summary);
        $achievements = $this->achievements->catalog($profile, $summary);
        // Marca como vistas depois de montar o catalogo: as novas aparecem destacadas so desta vez.
        $this->achievements->markSeen($profile);

        $friendRequests = $profile->friendedBy()->wherePivot('status', FriendshipService::PENDING)->with('playerProfile')->get();
        $friendshipStatus = null;

        $decks = $profile->decks()->withSum('cards', 'quantity')->orderByDesc('is_primary')->orderBy('name')->get();
        $friendsCount = $this->friendships->friends($request->user())->count();
        $showPreferredDeck = true;

        $ranked = $this->ranked($profile, $profile->account_user_id === $request->user()->id);

        return view('players.show', compact('profile', 'summary', 'achievements', 'friendRequests', 'friendshipStatus', 'decks', 'friendsCount', 'showPreferredDeck', 'ranked'));
    }

    public function show(Request $request, PlayerProfile $profile): View
    {
        $user = $request->user();
        $isOwner = $profile->account_user_id === $user->id;
        // Amigo de amigo ve o perfil (sem decks) se a pessoa aparece na busca (discoverable).
        $viaFriend = ! $isOwner && $profile->discoverable && $this->friendships->isFriendOfFriend($user, $profile);
        abort_unless($isOwner || $viaFriend || $this->organizedTournamentWith($user, $profile) || $this->hasRelationship($user, $profile), 403);

        $summary = $this->stats->for($profile);
        $this->achievements->sync($profile, $summary);
        $achievements = $this->achievements->catalog($profile, $summary);
        $friendRequests = collect();

        $friendshipStatus = $this->friendships->status($user, $profile);
        if ($isOwner) {
            $decks = $profile->decks()->withSum('cards', 'quantity')->orderByDesc('is_primary')->orderBy('name')->get();
        } elseif ($friendshipStatus === FriendshipService::ACCEPTED) {
            $decks = $profile->decks()->where('is_visible_to_friends', true)->withSum('cards', 'quantity')->orderByDesc('is_primary')->orderBy('name')->get();
        } else {
            $decks = collect();
        }
        $showPreferredDeck = $isOwner || $decks->contains('is_primary', true);
        $friendsCount = null;

        $ranked = $this->ranked($profile, $profile->account_user_id === $request->user()->id);
        // Lista de amigos: o dono e os amigos aceitos veem; amigo de amigo nao (evita navegar a rede inteira).
        $profileFriends = ($isOwner || $friendshipStatus === FriendshipService::ACCEPTED) ? $this->friendships->friendsOf($user, $profile) : null;

        return view('players.show', compact('profile', 'summary', 'achievements', 'friendRequests', 'friendshipStatus', 'decks', 'friendsCount', 'showPreferredDeck', 'ranked', 'profileFriends'));
    }

    /** Patente por formato (temporadas em andamento) + historico. Visivel para quem pode ver o perfil. */
    private function ranked(PlayerProfile $profile, bool $isOwner): ?array
    {
        if (! config('ranked.enabled') || ! $profile->account_user_id) {
            return null;
        }
        app(SeasonService::class)->refresh();
        $ratings = PlayerRating::query()->with('season')->where('player_profile_id', $profile->id)
            ->whereHas('season', fn ($q) => $q->whereIn('status', ['active', 'closing']))->orderByDesc('rating')->get();
        $history = RatingTransaction::query()->with(['rankedMatch.playerOne', 'rankedMatch.playerTwo'])
            ->where('player_profile_id', $profile->id)->latest('effective_at')->latest('id')->limit(8)->get();
        $snapshots = RatingSeasonSnapshot::query()->with('season')->where('player_profile_id', $profile->id)->latest('id')->limit(6)->get();
        $activeFormats = RankedSeason::query()->where('status', 'active')->where('scope', 'global')->with('formats')->get()
            ->flatMap(fn ($s) => $s->formats->where('enabled', true)->pluck('format'))->unique()->values();
        $currentSeason = RankedSeason::query()->where('status', 'active')->where('scope', 'global')->orderBy('starts_at')->first();

        return ['ratings' => $ratings, 'history' => $history, 'snapshots' => $snapshots, 'activeFormats' => $activeFormats, 'currentSeason' => $currentSeason,
            'start' => (int) config('ranked.defaults.starting_rating'), 'tiers' => app(RankTierResolver::class)];
    }

    public function edit(Request $request): View
    {
        $profile = $this->ownProfile($request)->load(['decks' => fn ($query) => $query->withSum('cards', 'quantity')]);

        return view('players.edit', compact('profile'));
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = $this->ownProfile($request);
        $validated = $request->validate(['nickname' => ['required', 'string', 'max:80'], 'avatar' => ['nullable', 'string', 'max:20']]);
        $profile->update($validated);

        return redirect()->route('profiles.index')->with('status', 'Perfil atualizado.');
    }

    /** Botao de amizade da pagina de perfil (perfis conhecidos via torneio ou relacao existente). */
    public function toggleFriend(Request $request, PlayerProfile $profile): RedirectResponse
    {
        $user = $request->user();
        abort_if($profile->account_user_id === $user->id, 422, 'Você não pode adicionar a si mesmo.');

        $status = $this->friendships->status($user, $profile);
        if ($status) {
            $this->friendships->remove($user, $profile);

            return back()->with('status', $status === FriendshipService::ACCEPTED ? 'Amizade removida.' : 'Solicitação cancelada.');
        }

        $incoming = $this->friendships->hasIncomingRequest($user, $profile);
        abort_unless($incoming || $this->organizedTournamentWith($user, $profile), 403);
        abort_unless($profile->account_user_id, 422, 'Este perfil ainda não possui uma conta.');

        return match ($this->friendships->request($user, $profile)) {
            'accepted' => back()->with('status', 'Vocês agora são amigos.'),
            'limit' => back()->withErrors(['friend' => 'Você tem pedidos pendentes demais. Aguarde respostas ou cancele alguns.']),
            default => back()->with('status', 'Solicitação de amizade enviada.'),
        };
    }

    private function ownProfile(Request $request): PlayerProfile
    {
        return $request->user()->ensurePlayerProfile();
    }

    private function organizedTournamentWith(User $user, PlayerProfile $profile): bool
    {
        return $profile->entries()->whereHas('tournament', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    /** Existe amizade ou pedido em qualquer um dos sentidos. */
    private function hasRelationship(User $user, PlayerProfile $profile): bool
    {
        return $this->friendships->status($user, $profile) !== null
            || $this->friendships->hasIncomingRequest($user, $profile);
    }
}
