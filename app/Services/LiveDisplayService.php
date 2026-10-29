<?php
namespace App\Services;
use App\Models\Tournament;
class LiveDisplayService
{
    public function snapshot(Tournament $t): array
    {
        $ranking = app(StandingsService::class)->for($t);
        // Explicit public allowlist: never serialize Eloquent models or submissions.
        return [
            'name'=>$t->name, 'format'=>$t->format, 'status'=>$t->status,
            // Fim da rodada atual (cronômetro do organizador); o navegador conta o tempo.
            'round_ends_at'=>$t->roundTimerRunning() ? $t->round_ends_at->toIso8601String() : null,
            'standings'=>$ranking->map(fn($r)=>[
                'rank'=>$r['rank'],'name'=>$r['player']->displayName(),'points'=>$r['points'],
                'played'=>$r['played'],'wins'=>$r['wins'],'draws'=>$r['draws'],'losses'=>$r['losses'],'streak'=>$r['streak'],
            ])->all(),
            'matches'=>$t->matches()->with(['playerOne.profile','playerTwo.profile'])->orderBy('round')->orderBy('id')->get()->map(fn($m)=>[
                'id'=>$m->id,'round'=>$m->round,'stage'=>$m->stage,'status'=>$m->status,
                'one'=>$m->playerOne?->displayName() ?? 'A definir','two'=>$m->playerTwo?->displayName() ?? 'BYE',
                'one_score'=>in_array($m->status,['finished','bye']) ? $m->player_one_games : null,
                'two_score'=>in_array($m->status,['finished','bye']) ? $m->player_two_games : null,
            ])->all(),
        ];
    }
}
