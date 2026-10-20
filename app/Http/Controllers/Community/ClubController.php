<?php
namespace App\Http\Controllers\Community;
use App\Http\Controllers\Controller;
use App\Models\{Club,User,Tournament};
use App\Services\Community\ClubService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class ClubController extends Controller {
 public function index(Request $request){$clubs=Club::where('privacy','public')->orWhereHas('members',fn($q)=>$q->where('users.id',$request->user()->id))->orderBy('name')->paginate(20);return view('community.clubs.index',compact('clubs'));}
 public function store(Request $request,ClubService $service){$club=$service->create($request->user(),$this->validated($request));return redirect()->route('clubs.show',$club)->with('status','Clube criado.');}
 public function show(Club $club){$this->authorize('view',$club);$club->load('members');$tournaments=Tournament::where('club_id',$club->id)->latest()->get();return view('community.clubs.show',compact('club','tournaments'));}
 public function update(Request $request,Club $club){$this->authorize('update',$club);$club->update($this->validated($request));return back()->with('status','Clube atualizado.');}
 public function join(Request $request,Club $club){abort_unless($club->privacy==='public',403);$club->members()->syncWithoutDetaching([$request->user()->id]);return back()->with('status','Você entrou no clube.');}
 public function addMember(Request $request,Club $club){$this->authorize('manage',$club);$data=$request->validate(['email'=>['required','email','exists:users,email']]);$user=User::where('email',$data['email'])->firstOrFail();$club->members()->syncWithoutDetaching([$user->id]);return back()->with('status','Membro adicionado.');}
 public function role(Request $request,Club $club,User $user){$this->authorize('update',$club);$data=$request->validate(['role'=>['required',Rule::in(['member','organizer'])]]);abort_if($club->roleFor($user)==='owner',409,'O dono não pode ser rebaixado.');abort_unless($club->roleFor($user),404);$club->members()->updateExistingPivot($user->id,$data);return back()->with('status','Papel atualizado.');}
 public function remove(Request $request,Club $club,User $user){$role=$club->roleFor($user);abort_unless($role,404);abort_if($role==='owner',409,'O dono deve permanecer no clube.');if($request->user()->id!==$user->id){$this->authorize('manage',$club);if($role==='organizer')$this->authorize('update',$club);}$club->members()->detach($user->id);return redirect()->route('clubs.index')->with('status','Membro removido.');}
 public function tournament(Request $request,Club $club){$this->authorize('manage',$club);$data=$request->validate(['name'=>['required','string','max:120'],'format'=>['required',Rule::in(['Pauper','Commander','Standard','Modern','Draft','Outro'])],'tournament_date'=>['required','date'],'max_players'=>['nullable','integer','min:4','max:128']]);$tournament=DB::transaction(function()use($request,$club,$data){$t=new Tournament($data+['public_token'=>Str::random(40),'public_slug'=>Str::slug($data['name']).'-'.Str::lower(Str::random(16)),'invite_token'=>Str::random(40)]);$t->user_id=$request->user()->id;$t->club_id=$club->id;$t->save();return $t;});return redirect()->route('tournaments.show',$tournament);}
 private function validated(Request $request):array{return $request->validate(['name'=>['required','string','max:120'],'description'=>['nullable','string','max:2000'],'privacy'=>['required',Rule::in(['private','public'])]]);}
}
