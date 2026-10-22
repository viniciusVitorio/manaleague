<?php

namespace App\Services\Community;

use App\Models\Club;
use App\Models\ClubPost;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TournamentChampionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClubService
{
    public function create(User $owner, array $data): Club
    {
        return DB::transaction(function () use ($owner, $data) {
            $club = Club::create($data);
            $club->forceFill(['invite_token' => Str::random(40)])->save();
            $club->members()->attach($owner, ['role' => 'owner']);

            return $club;
        });
    }

    public function inviteToken(Club $club): string
    {
        if (! $club->invite_token) {
            $club->forceFill(['invite_token' => Str::random(40)])->save();
        }

        return $club->invite_token;
    }

    public function regenerateInvite(Club $club): void
    {
        $club->forceFill(['invite_token' => Str::random(40)])->save();
    }

    public function join(Club $club, User $user): bool
    {
        if ($club->roleFor($user)) {
            return false;
        }
        $club->members()->attach($user->id, ['role' => 'member']);
        app(ActivityService::class)->record($user, 'club.joined', ($user->playerProfile?->nickname ?? $user->name).' entrou no clube '.$club->name, $club, "club:{$club->id}:joined:{$user->id}");

        return true;
    }

    /** Aviso no mural: notifica todos os membros (menos o autor). */
    public function post(Club $club, User $author, string $body, bool $pinned): ClubPost
    {
        return DB::transaction(function () use ($club, $author, $body, $pinned) {
            $post = $club->posts()->create(['user_id' => $author->id, 'body' => $body, 'pinned' => $pinned]);
            $preview = Str::limit(preg_replace('/\s+/', ' ', $body), 70);
            foreach ($club->members()->where('users.id', '!=', $author->id)->get() as $member) {
                app(NotificationService::class)->send($member, 'club.post', "Aviso em {$club->name}: {$preview}", '/clubs/'.$club->id.'#mural', "club:{$club->id}:post:{$post->id}:{$member->id}", ['club_id' => $club->id]);
            }

            return $post;
        });
    }

    /**
     * Ranking acumulado dos torneios do clube (1v1): 3 por vitória, 1 por empate; títulos desempatam.
     * Jogadores com perfil são agrupados pelo perfil; convidados sem conta, pelo nome.
     *
     * @return Collection<int, array{key:string, name:string, profile_id:?int, events:int, wins:int, draws:int, losses:int, points:int, titles:int}>
     */
    public function ranking(Club $club): Collection
    {
        $tournaments = $club->tournaments()->where('status', '!=', Tournament::STATUS_SETUP)->with(['players.profile', 'matches'])->get();
        $rows = [];
        $row = function ($player) use (&$rows) {
            $key = $player->player_profile_id ? 'p'.$player->player_profile_id : 'n'.mb_strtolower(trim($player->name));
            $rows[$key] ??= ['key' => $key, 'name' => $player->name, 'profile_id' => $player->player_profile_id, 'avatar' => $player->profile?->avatar, 'events' => [], 'wins' => 0, 'draws' => 0, 'losses' => 0, 'points' => 0, 'titles' => 0];

            return $key;
        };
        $champions = app(TournamentChampionService::class);
        foreach ($tournaments as $t) {
            $players = $t->players->keyBy('id');
            foreach ($t->players as $p) {
                $rows[$row($p)]['events'][$t->id] = true;
            }
            foreach ($t->matches as $m) {
                if ($m->status !== GameMatch::STATUS_FINISHED || $m->pod_player_ids || ! $m->player_one_id || ! $m->player_two_id) {
                    continue;
                }
                $one = $players->get($m->player_one_id);
                $two = $players->get($m->player_two_id);
                if (! $one || ! $two) {
                    continue;
                }
                [$k1, $k2] = [$row($one), $row($two)];
                if ($m->player_one_games === $m->player_two_games) {
                    $rows[$k1]['draws']++;
                    $rows[$k2]['draws']++;

                    continue;
                }
                [$w, $l] = $m->player_one_games > $m->player_two_games ? [$k1, $k2] : [$k2, $k1];
                $rows[$w]['wins']++;
                $rows[$l]['losses']++;
            }
            if ($champ = $champions->for($t)) {
                $rows[$row($champ)]['titles']++;
            }
        }

        return collect($rows)->map(function ($r) {
            $r['events'] = count($r['events']);
            $r['points'] = $r['wins'] * 3 + $r['draws'];

            return $r;
        })->sortBy([fn ($a, $b) => $b['points'] <=> $a['points'], fn ($a, $b) => $b['titles'] <=> $a['titles'], fn ($a, $b) => $b['wins'] <=> $a['wins'], fn ($a, $b) => strcmp($a['name'], $b['name'])])->values();
    }
}
