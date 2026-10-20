<?php
namespace App\Services\Community;
use App\Models\{User,Club,Tournament,GameMatch,Deck,Challenge,CasualMatch};
use Illuminate\Database\Eloquent\Model;
class CommunityAccess {
 public const SUBJECTS=['club'=>Club::class,'tournament'=>Tournament::class,'match'=>GameMatch::class,'deck'=>Deck::class,'challenge'=>Challenge::class,'casual_match'=>CasualMatch::class,'ranked_match'=>\App\Models\RankedMatch::class];
 public function allows(User $user,string $type,int $id):bool {
  $class=self::SUBJECTS[$type]??null;
  if(!$class || !($subject=$class::find($id)))return false;
  return match($type){
   'club'=>$user->can('view',$subject),
   'tournament'=>$user->can('view',$subject) || $this->participates($user,$subject),
   'match'=>$subject->tournament && ($user->can('view',$subject->tournament) || $this->participates($user,$subject->tournament)),
   'deck'=>app(\App\Services\Decks\DeckAccessService::class)->visible($user)->whereKey($id)->exists(),
   'challenge'=>$subject->involves($user),
   'casual_match'=>$subject->challenge?->involves($user)??false,
   'ranked_match'=>$user->can('view',$subject),
   default=>false,
  };
 }
 /** Players with an account linked to an entry can see their tournament's events (read-only). */
 public function participates(User $user,Tournament $tournament):bool {
  return $tournament->players()->whereHas('profile',fn($q)=>$q->where('account_user_id',$user->id))->exists();
 }
 /** Best destination for this viewer: organizer page when allowed, otherwise the public arena. */
 public function link(User $user,string $type,int $id):string {
  if($type==='tournament' || $type==='match'){
   $tournament=$type==='tournament'?Tournament::find($id):GameMatch::find($id)?->tournament;
   if(!$tournament)return '/feed';
   if($type==='match' && $this->participates($user,$tournament))return '/me/matches';
   return $user->can('view',$tournament)?'/tournaments/'.$tournament->id:'/t/'.$tournament->public_slug;
  }
  return $this->url($type,$id);
 }
 public function type(Model $subject):string {
  $type=array_search($subject::class,self::SUBJECTS,true);
  if($type===false || !$subject->exists)throw new \InvalidArgumentException('A persisted supported subject is required.');
  return $type;
 }
 public function url(string $type,int $id):string {
  return match($type){'club'=>'/clubs/'.$id,'tournament'=>'/tournaments/'.$id,'deck'=>'/decks/'.$id,'match'=>'/me/matches','challenge','casual_match'=>'/challenges','ranked_match'=>'/ranked/matches/'.$id,default=>'/feed'};
 }
}
