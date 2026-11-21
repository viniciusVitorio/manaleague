<?php
namespace App\Services;
use App\Models\Tournament;
class LiveDisplayService
{
    public function snapshot(Tournament $t): array
    {
        $ranking = app(StandingsService::class)->for($t);
        $podPlayers = $t->system === 'pod_elimination' ? $t->players()->with('profile')->get()->keyBy('id') : collect();
        // Explicit public allowlist: never serialize Eloquent models or submissions.
        return [
            'name'=>$t->name, 'format'=>$t->format, 'status'=>$t->status, 'system'=>$t->system,
            // Fim da rodada atual (cronômetro do organizador); o navegador conta o tempo.
            'round_ends_at'=>$t->roundTimerRunning() ? $t->round_ends_at->toIso8601String() : null,
            'standings'=>$ranking->map(fn($r)=>[
                'rank'=>$r['rank'],'name'=>$r['player']->displayName(),'points'=>$r['points'],
                'played'=>$r['played'],'wins'=>$r['wins'],'draws'=>$r['draws'],'losses'=>$r['losses'],'streak'=>$r['streak'],
            ])->all(),
            'matches'=>$t->matches()->with(['playerOne.profile','playerTwo.profile','tournament'])->orderBy('round')->orderBy('id')->get()->map(fn($m)=>[
                'id'=>$m->id,'round'=>$m->round,'stage'=>$m->stage,'status'=>$m->status,
                'one'=>$m->playerOne?->displayName() ?? 'A definir','two'=>$m->playerTwo?->displayName() ?? 'BYE',
                'one_score'=>in_array($m->status,['finished','bye']) ? $m->player_one_games : null,
                'two_score'=>in_array($m->status,['finished','bye']) ? $m->player_two_games : null,
                ...($t->system === 'pod_elimination' ? [
                    'pod_number' => $m->pod_number, 'phase' => $m->podPhaseLabel(), 'confirmed' => $m->pod_confirmed_at !== null,
                    'seats' => collect($m->pod_player_ids)->map(fn ($id) => [
                        'name' => $podPlayers->get($id)?->displayName() ?? 'A definir',
                        'place' => $m->pod_confirmed_at ? (($m->pod_tiebreak_scores ?: $m->pod_scores)[$id] ?? null) : null,
                        'advancing' => $m->pod_confirmed_at && $m->stage === 'league' && (($m->pod_tiebreak_scores ?: $m->pod_scores)[$id] ?? 99) <= $t->pod_qualifiers,
                    ])->all(),
                ] : []),
            ])->all(),
        ];
    }
}
