<?php
namespace App\Http\Controllers;
use App\Models\{Tournament,GameMatch,MatchResultSubmission};
use App\Services\MatchResultSubmissionService;
use Illuminate\Http\Request;
class MatchResultSubmissionController extends Controller {
 public function store(Request $r,Tournament $tournament,GameMatch $match,MatchResultSubmissionService $service) {
  $data = $r->validate(['player_one_games'=>'required|integer|min:0|max:3','player_two_games'=>'required|integer|min:0|max:3','idempotency_key'=>'required|uuid']);
  $s = $service->submit($tournament,$match,$r->user(),$data);
  return $r->expectsJson() ? response()->json(['id'=>$s->id,'status'=>$s->status],$s->wasRecentlyCreated ? 201 : 200) : back()->with('status','Resultado enviado. Aguarde a confirmação do adversário.');
 }
 public function respond(Request $r,MatchResultSubmission $submission,MatchResultSubmissionService $service) {
  $data=$r->validate(['action'=>'required|in:confirm,dispute','reason'=>'required_if:action,dispute|nullable|string|min:3|max:1000']);
  $s=$service->review($submission,$r->user(),$data['action'],$data);
  return $r->expectsJson() ? response()->json(['id'=>$s->id,'status'=>$s->status]) : back()->with('status','Resposta registrada.');
 }
 public function resolve(Request $r,MatchResultSubmission $submission,MatchResultSubmissionService $service) {
  $this->authorize('update',$submission->gameMatch->tournament);
  $data=$r->validate(['player_one_games'=>'required|integer|min:0|max:3','player_two_games'=>'required|integer|min:0|max:3','reason'=>'required|string|min:3|max:1000']);
  $s=$service->review($submission,$r->user(),'resolve',$data);
  return $r->expectsJson() ? response()->json(['id'=>$s->id,'status'=>$s->status]) : back()->with('status','Resultado resolvido e publicado.');
 }
 public function queue(Tournament $tournament) {
  $this->authorize('update',$tournament);
  $submissions=MatchResultSubmission::whereHas('gameMatch',fn($q)=>$q->where('tournament_id',$tournament->id))->with(['gameMatch.playerOne','gameMatch.playerTwo'])->orderByDesc('id')->paginate(30);
  $events=\Illuminate\Support\Facades\DB::table('match_result_submission_events')->whereIn('submission_id',$submissions->pluck('id'))->orderBy('id')->get()->groupBy('submission_id');
  return response()->view('matches.queue',compact('tournament','submissions','events'))->header('Cache-Control','private, no-store');
 }
 public function index(Request $r, \App\Services\MyMatchesService $matches) {
  return view('matches.mine', $matches->for($r->user(), (string) $r->query('tipo', 'todas')));
 }
}
