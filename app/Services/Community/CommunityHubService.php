<?php

namespace App\Services\Community;

use App\Models\CommunityActivity;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlayerProfile;
use App\Models\PlayerRating;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FriendshipService;
use App\Services\Ranked\RankTierResolver;
use Illuminate\Support\Collection;

/** Dados da página Comunidade: feed filtrável, ranking entre amigos, destaques da semana e agenda. */
class CommunityHubService
{
    public const FILTERS = ['all' => 'Tudo', 'tournament' => 'Torneios', 'match' => 'Partidas', 'challenge' => 'Desafios', 'ranked' => 'Ranked', 'club' => 'Clubes'];

    public function __construct(private readonly CommunityAccess $access, private readonly FriendshipService $friends) {}

    /** Atividades visíveis agora (acesso rechecado em cada leitura). */
    public function feed(User $user, string $filter, int $limit = 40): Collection
    {
        $query = CommunityActivity::query()->with('actor.playerProfile')->latest('id');
        if ($filter !== 'all') {
            $query->where('type', 'like', $filter.'.%');
        }

        return $query->limit(400)->get()->filter(fn ($e) => $this->access->allows($user, $e->subject_type, $e->subject_id))->take($limit)->values();
    }

    /** Eu + amigos, por formato, na temporada ranqueada ativa. */
    public function friendsRanking(User $user): Collection
    {
        $ids = $this->friends->friendIds($user->id)->push($user->ensurePlayerProfile()->id);
        $tiers = app(RankTierResolver::class);

        return PlayerRating::query()->with('profile')->whereIn('player_profile_id', $ids)
            ->whereHas('season', fn ($q) => $q->whereIn('status', ['active', 'closing'])->where('scope', 'global'))
            ->orderByDesc('rating')->get()->groupBy('format')
            ->map(fn ($rows) => $rows->take(10)->values()->map(fn ($r) => ['profile' => $r->profile, 'rating' => $r->rating, 'tier' => $tiers->resolve($r->rating), 'me' => $r->player_profile_id === $user->playerProfile->id, 'record' => $r->wins.'V '.$r->losses.'D']))
            ->sortByDesc(fn ($rows) => $rows->count());
    }

    /** Quem mais venceu partidas de torneio nos últimos 7 dias (eu + amigos). */
    public function weeklyWinners(User $user): Collection
    {
        $ids = $this->friends->friendIds($user->id)->push($user->ensurePlayerProfile()->id);
        $players = Player::query()->whereIn('player_profile_id', $ids)->pluck('player_profile_id', 'id');
        if ($players->isEmpty()) {
            return collect();
        }
        $wins = collect();
        GameMatch::query()->where('status', GameMatch::STATUS_FINISHED)->where('updated_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q->whereIn('player_one_id', $players->keys())->orWhereIn('player_two_id', $players->keys()))
            ->get(['player_one_id', 'player_two_id', 'player_one_games', 'player_two_games'])
            ->each(function ($m) use ($players, $wins) {
                if ($m->player_one_games === $m->player_two_games) {
                    return;
                }
                $winner = $m->player_one_games > $m->player_two_games ? $m->player_one_id : $m->player_two_id;
                if ($profile = $players->get($winner)) {
                    $wins->put($profile, $wins->get($profile, 0) + 1);
                }
            });
        $profiles = PlayerProfile::whereIn('id', $wins->keys())->get()->keyBy('id');

        return $wins->sortDesc()->take(5)->map(fn ($n, $id) => ['profile' => $profiles[$id], 'wins' => $n])->values();
    }

    /** Próximos torneios dos meus clubes e os que eu organizo ou em que estou inscrito. */
    public function upcoming(User $user): Collection
    {
        $profileId = $user->ensurePlayerProfile()->id;

        return Tournament::query()->where('status', Tournament::STATUS_SETUP)->whereDate('tournament_date', '>=', today())
            ->where(fn ($q) => $q->whereIn('club_id', $user->clubs()->pluck('clubs.id'))->orWhere('user_id', $user->id)
                ->orWhereHas('players', fn ($p) => $p->where('player_profile_id', $profileId)))
            ->orderBy('tournament_date')->limit(6)->get()->filter(fn ($t) => $user->can('view', $t))->values();
    }
}
