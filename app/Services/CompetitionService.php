<?php
namespace App\Services;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use LogicException;
class CompetitionService
{
    public function start(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament): void {
            $t=Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            if ($t->status!=='setup'||$t->matches()->exists()) throw new LogicException('Este torneio já foi iniciado.');
            $n=$t->players()->count();
            if ($n<4) throw new LogicException('Cadastre pelo menos quatro jogadores.');
            if (!in_array($t->system,['swiss','single_elimination','double_elimination','commander_pods'],true)) throw new LogicException('Sistema não suportado.');
            if ($t->system==='commander_pods'&&$t->format!=='Commander') throw new LogicException('Pods exigem formato Commander.');
            app(\App\Services\Decks\DeckVersionService::class)->snapshotForTournament($t);
            $t->update(['status'=>'league','round_limit'=>$t->round_limit??(int)ceil(log($n,2))]);
            $this->generate($t,1);
        });
    }
    public function advance(Tournament $tournament, int $expectedRound): void
    {
        DB::transaction(function () use ($tournament,$expectedRound): void {
            $t=Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $round=(int)$t->matches()->max('round');
            if ($t->system==='round_robin'||$t->status!=='league'||$round!==$expectedRound) throw new LogicException('A rodada mudou ou o torneio já foi encerrado.');
            if ($t->matches()->where('status','pending')->exists()) throw new LogicException('Registre todos os resultados antes de avançar.');
            if (in_array($t->system,['swiss','commander_pods'])&&$round>=$t->round_limit) { $t->update(['status'=>'finished']); return; }
            $this->generate($t,$round+1);
        });
    }
    private function generate(Tournament $t,int $round): void
    {
        $ids=app(StandingsService::class)->for($t)->pluck('player.id')->all();
        if ($t->system==='commander_pods') {
            $count=(int)ceil(count($ids)/4); $pods=array_fill(0,$count,[]);
            foreach($ids as $i=>$id) $pods[($i+$round-1)%$count][]=$id;
            foreach($pods as $pod) $t->matches()->create(['round'=>$round,'stage'=>'league','bracket'=>'pod','player_one_id'=>$pod[0],'player_two_id'=>$pod[1],'pod_player_ids'=>$pod,'status'=>'pending']);
            return;
        }
        if ($t->system==='swiss') {
            $history=[]; $byes=[];
            foreach($t->matches()->get() as $m) {
                if ($m->status==='bye') $byes[$m->player_one_id]=($byes[$m->player_one_id]??0)+1;
                else { $history[$m->player_one_id][$m->player_two_id]=true; $history[$m->player_two_id][$m->player_one_id]=true; }
            }
            $bye=null;
            if (count($ids)%2) {
                $candidates=array_reverse($ids); usort($candidates,fn($a,$b)=>($byes[$a]??0)<=>($byes[$b]??0));
                $bye=$candidates[0]; $ids=array_values(array_diff($ids,[$bye]));
            }
            $budget=20000;
            $pairs=$this->noRematches($ids,$history,$budget)??array_chunk($ids,2);
            foreach($pairs as $pair) $this->pairs($t,$pair,$round,'swiss');
            if ($bye!==null) $this->pairs($t,[$bye],$round,'swiss');
            return;
        }
        $losses=array_fill_keys($ids,0);
        foreach($t->matches()->where('status','finished')->get() as $m) {
            $loser=$m->player_one_games>$m->player_two_games?$m->player_two_id:$m->player_one_id; $losses[$loser]++;
        }
        $limit=$t->system==='single_elimination'?1:2;
        $alive=array_values(array_filter($ids,fn($id)=>$losses[$id]<$limit));
        if (count($alive)===1) {
            $t->matches()->where('round',$round-1)->where('status','finished')->latest('id')->first()?->update(['stage'=>'final']);
            $t->update(['status'=>'finished']); return;
        }
        if ($limit===1) { $this->pairs($t,$alive,$round,count($alive)===2?'final':'winners'); return; }
        $winners=array_values(array_filter($alive,fn($id)=>$losses[$id]===0));
        $losers=array_values(array_filter($alive,fn($id)=>$losses[$id]===1));
        if (count($alive)===2) { $this->pairs($t,array_merge($winners,$losers),$round,count($winners)===1?'grand_final':'reset_final'); return; }
        if (count($winners)>1) $this->pairs($t,$winners,$round,'winners');
        if (count($losers)>1) $this->pairs($t,$losers,$round,'losers');
    }
    private function noRematches(array $ids,array $history,int &$budget): ?array
    {
        if (!$ids) return [];
        if (--$budget<0) return null;
        $one=array_shift($ids);
        foreach($ids as $index=>$two) {
            if (isset($history[$one][$two])) continue;
            $rest=$ids; unset($rest[$index]);
            $pairs=$this->noRematches(array_values($rest),$history,$budget);
            if ($pairs!==null) return [[$one,$two],...$pairs];
        }
        return null;
    }
    private function pairs(Tournament $t,array $ids,int $round,string $bracket): void
    {
        if (count($ids)%2) $ids[]=null;
        foreach(array_chunk($ids,2) as [$one,$two]) $t->matches()->create([
            'round'=>$round,'stage'=>'league','bracket'=>$bracket,'player_one_id'=>$one,'player_two_id'=>$two,
            'player_one_games'=>$two===null?2:null,'player_two_games'=>$two===null?0:null,
            'status'=>$two===null?'bye':'pending','played_at'=>$two===null?now():null,
        ]);
    }
}
