<?php
namespace App\Services;
use App\Models\{Tournament,GameMatch,User,MatchResultSubmission};
use App\Policies\MatchResultSubmissionPolicy;
use App\Services\Community\CommunityEvents;
use Illuminate\Support\Facades\DB;
class MatchResultSubmissionService {
 public function submit(Tournament $tournament, GameMatch $match, User $user, array $data): MatchResultSubmission {
  $submission = DB::transaction(function() use($tournament,$match,$user,$data) {
   $tournament = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
   $match = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
   abort_unless($match->tournament_id === $tournament->id,404);
   abort_unless((new MatchResultSubmissionPolicy)->participate($user,$match),403);
   $existing = MatchResultSubmission::where('match_id',$match->id)->first();
   if ($existing) {
    abort_unless($existing->submitted_by === $user->id && $existing->idempotency_key === $data['idempotency_key'] && (int)$existing->player_one_games === (int)$data['player_one_games'] && (int)$existing->player_two_games === (int)$data['player_two_games'],409,'Já existe um resultado enviado.');
    return $existing;
   }
   abort_if(MatchResultSubmission::where('submitted_by',$user->id)->where('idempotency_key',$data['idempotency_key'])->exists(),409);
   $this->assertOpen($tournament,$match);
   $this->scores($match,$data);
   $submission = MatchResultSubmission::create([...$data,'match_id'=>$match->id,'submitted_by'=>$user->id,'status'=>'pending']);
   $this->audit($submission,$user,'submitted',$data);
   return $submission;
  },3);
  if ($submission->wasRecentlyCreated) app(CommunityEvents::class)->resultSubmitted($submission);
  return $submission;
 }
 public function review(MatchResultSubmission $submission,User $user,string $action,array $data): MatchResultSubmission {
  $changed = false;
  $result = DB::transaction(function() use($submission,$user,$action,$data,&$changed) {
   $t = Tournament::query()->lockForUpdate()->findOrFail($submission->gameMatch->tournament_id);
   $m = GameMatch::query()->lockForUpdate()->findOrFail($submission->match_id);
   $s = MatchResultSubmission::query()->lockForUpdate()->findOrFail($submission->id);
   if ($action === 'resolve') abort_unless($user->can('update',$t),403);
   else abort_unless((new MatchResultSubmissionPolicy)->respond($user,$s),403);
   $target = ['confirm'=>'confirmed','dispute'=>'disputed','resolve'=>'resolved'][$action];
   if ($s->status === $target) {
    $same = $action === 'confirm' || ($action === 'dispute' ? $s->dispute_reason === $data['reason'] : ((int)$s->player_one_games === (int)$data['player_one_games'] && (int)$s->player_two_games === (int)$data['player_two_games'] && $s->resolution_reason === $data['reason']));
    abort_unless($same,409,'Repetição incompatível.'); return $s;
   }
   abort_unless($action === 'resolve' ? in_array($s->status,['pending','disputed'],true) : $s->status === 'pending',409,'Resultado já revisado.');
   $this->assertOpen($t,$m);
   $before = $s->only(['status','player_one_games','player_two_games']);
   if ($action === 'dispute') $s->dispute_reason = $data['reason'];
   else {
    $scores = $action === 'resolve' ? array_intersect_key($data,array_flip(['player_one_games','player_two_games'])) : $s->only(['player_one_games','player_two_games']);
    $this->scores($m,$scores);
    app(MatchResultService::class)->record($t,$m,$scores);
    $s->fill($scores);
    if ($action === 'resolve') $s->resolution_reason = $data['reason'];
   }
   $s->status = $target; $s->reviewed_by = $user->id; $s->save();
   $this->audit($s,$user,$action,['before'=>$before,'after'=>$s->only(['status','player_one_games','player_two_games']),'reason'=>$data['reason'] ?? null]);
   $changed = true;
   return $s;
  },3);
  if ($changed) app(CommunityEvents::class)->resultReviewed($result,$user,$action);
  return $result;
 }
 private function assertOpen(Tournament $t, GameMatch $m): void {
  abort_unless($m->status === 'pending' && $m->player_two_id !== null && ! $m->pod_player_ids,409,'Partida encerrada, BYE ou mesa multiplayer.');
  abort_unless(app(MatchResultService::class)->editable($t,$m),409,'Fase encerrada.');
 }
 private function scores(GameMatch $m,array $data): void {
  abort_if($data['player_one_games'] + $data['player_two_games'] === 0,422,'O placar não pode ser 0 a 0.');
  $tournament = $m->tournament;
  $decisive = $m->stage !== 'league' || in_array($tournament?->system,['single_elimination','double_elimination'],true);
  abort_if($decisive && (int)$data['player_one_games'] === (int)$data['player_two_games'],422,'Decisão não permite empate.');
 }
 private function audit(MatchResultSubmission $s,User $u,string $action,array $data): void {
  DB::table('match_result_submission_events')->insert(['submission_id'=>$s->id,'actor_id'=>$u->id,'action'=>$action,'data'=>json_encode($data,JSON_THROW_ON_ERROR),'created_at'=>now()]);
 }
}
