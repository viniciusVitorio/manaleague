<?php

namespace App\Services\Ranked;

use App\Models\MatchmakingProposal;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\User;
use App\Services\Community\ActivityService;
use App\Services\Community\NotificationService;

/** Notificacoes e feed do ranked. Chaves estaveis: repetir nunca duplica. */
class RankedNotifier
{
    public function __construct(private readonly NotificationService $notifications, private readonly ActivityService $activities) {}

    public function matchCreated(RankedMatch $m): void
    {
        $m->loadMissing(['playerOne', 'playerTwo']);
        foreach ([[$m->playerOne, $m->playerTwo], [$m->playerTwo, $m->playerOne]] as [$me, $other]) {
            $this->send($me, 'ranked.match', "Partida ranqueada marcada contra {$other->nickname} ({$m->format})", $m, "ranked:{$m->id}:created");
        }
    }

    public function proposal(MatchmakingProposal $p): void
    {
        $p->loadMissing(['entryOne.profile', 'entryTwo.profile']);
        foreach ([$p->entryOne, $p->entryTwo] as $entry) {
            $user = $this->user($entry->profile);
            if ($user) {
                $this->notifications->send($user, 'ranked.queue', "Oponente encontrado em {$entry->format}: aceite em até 3 minutos", '/ranked', "ranked:proposal:{$p->id}:user:{$user->id}");
            }
        }
    }

    public function resultSubmitted(RankedMatch $m, User $by): void
    {
        foreach ([$m->playerOne, $m->playerTwo] as $p) {
            if ($p->account_user_id !== $by->id) {
                $this->send($p, 'ranked.result', "Confirme o placar {$m->score_one}×{$m->score_two} da partida ranqueada", $m, "ranked:{$m->id}:submitted:{$m->updated_at?->timestamp}");
            }
        }
    }

    public function disputed(RankedMatch $m, User $by): void
    {
        foreach ([$m->playerOne, $m->playerTwo] as $p) {
            if ($p->account_user_id !== $by->id) {
                $this->send($p, 'ranked.result', 'Seu placar ranqueado foi contestado', $m, "ranked:{$m->id}:disputed:{$m->updated_at?->timestamp}");
            }
        }
        foreach ($this->moderators($m) as $mod) {
            $this->notifications->send($mod, 'ranked.moderation', "Disputa ranqueada #{$m->id} aguardando decisão", '/ranked/matches/'.$m->id, "ranked:{$m->id}:disputed:mod:{$mod->id}:{$m->updated_at?->timestamp}", ['ranked_match_id' => $m->id]);
        }
    }

    public function confirmed(RankedMatch $m, array $deltas): void
    {
        foreach ([$m->playerOne, $m->playerTwo] as $p) {
            $d = $deltas[$p->id] ?? 0;
            $this->send($p, 'ranked.result', 'Resultado confirmado: '.($d >= 0 ? '+' : '').$d.' PDL em '.$m->format, $m, "ranked:{$m->id}:confirmed");
        }
        $actor = User::find($m->playerOne->account_user_id);
        if ($actor) {
            $title = "{$m->playerOne->nickname} {$m->score_one}×{$m->score_two} {$m->playerTwo->nickname} · ranked {$m->format}";
            $this->activities->record($actor, 'ranked.confirmed', $title, $m, "ranked:{$m->id}:confirmed");
        }
    }

    public function invalidated(RankedMatch $m, array $changed): void
    {
        foreach ([$m->playerOne, $m->playerTwo] as $p) {
            $this->send($p, 'ranked.result', "Partida ranqueada #{$m->id} invalidada pela moderação; ratings recalculados", $m, "ranked:{$m->id}:invalidated");
        }
        foreach (array_keys($changed) as $profileId) {
            $profile = PlayerProfile::find($profileId);
            $user = $profile ? $this->user($profile) : null;
            if ($user && ! $m->involvesUser($user)) {
                $this->notifications->send($user, 'ranked.result', 'Seu rating foi corrigido após uma revisão da moderação', '/ranked', "ranked:{$m->id}:corrected:{$profileId}");
            }
        }
    }

    public function cancelled(RankedMatch $m, User $by): void
    {
        foreach ([$m->playerOne, $m->playerTwo] as $p) {
            if ($p->account_user_id !== $by->id) {
                $this->send($p, 'ranked.match', 'A partida ranqueada foi cancelada antes do resultado', $m, "ranked:{$m->id}:cancelled");
            }
        }
    }

    public function seasonFinished(RankedSeason $season): void
    {
        $profiles = PlayerProfile::query()->whereIn('id', $season->snapshots()->pluck('player_profile_id'))->get();
        foreach ($profiles as $profile) {
            $user = $this->user($profile);
            if ($user) {
                $this->notifications->send($user, 'ranked.season', "Temporada {$season->name} encerrada: confira sua patente final", '/ranked/seasons/'.$season->slug, "season:{$season->id}:finished:{$user->id}");
            }
        }
    }

    /** @return iterable<User> */
    public function moderators(RankedMatch $m): iterable
    {
        $m->loadMissing('season.club');
        $ids = User::query()->where('is_admin', true)->pluck('id');
        if ($m->season->scope === 'club' && $m->season->club) {
            $ids = $ids->merge($m->season->club->members()->wherePivotIn('role', ['owner', 'organizer'])->pluck('users.id'));
        }
        $participants = [$m->playerOne->account_user_id, $m->playerTwo->account_user_id];

        return User::query()->whereIn('id', $ids->unique()->reject(fn ($id) => in_array($id, $participants, true)))->get();
    }

    private function send(PlayerProfile $profile, string $type, string $title, RankedMatch $m, string $key): void
    {
        $user = $this->user($profile);
        if ($user) {
            $this->notifications->send($user, $type, $title, '/ranked/matches/'.$m->id, $key.':'.$user->id, ['ranked_match_id' => $m->id]);
        }
    }

    private function user(PlayerProfile $profile): ?User
    {
        return $profile->account_user_id ? User::find($profile->account_user_id) : null;
    }
}
