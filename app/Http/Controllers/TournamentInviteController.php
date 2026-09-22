<?php
namespace App\Http\Controllers;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
class TournamentInviteController extends Controller {
 public function show(string $token): View {$tournament=Tournament::query()->where('invite_token',$token)->firstOrFail();return view('tournaments.invite',compact('tournament'));}
 public function store(Request $request,string $token): RedirectResponse {
  $tournament=Tournament::query()->where('invite_token',$token)->firstOrFail();abort_unless($tournament->status===Tournament::STATUS_SETUP,409,'As inscricoes deste torneio foram encerradas.');
  $validated=$request->validate(['name'=>['required','string','max:80',Rule::unique('players')->where('tournament_id',$tournament->id)],'avatar'=>['nullable','string','max:255'],'deck_name'=>['nullable','string','max:100'],'deck_colors'=>['nullable','string','max:10','regex:/^[WUBRGC,]*$/']]);
  $profile=PlayerProfile::query()->firstOrCreate(['user_id'=>$tournament->user_id,'nickname'=>$validated['name']],['avatar'=>$validated['avatar']??null,'preferred_deck_name'=>$validated['deck_name']??null,'preferred_deck_colors'=>$validated['deck_colors']??null]);
  unset($validated['avatar']);$validated['player_profile_id']=$profile->id;$tournament->players()->create($validated);
  return back()->with('status','Inscricao confirmada. Nos vemos no torneio!');
 }
}