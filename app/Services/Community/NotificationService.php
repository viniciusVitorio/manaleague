<?php
namespace App\Services\Community;
use App\Models\{User,CommunityNotification,NotificationPreference};
class NotificationService {
 public function canRead(User $user,CommunityNotification $notification):bool {
  if($notification->user_id !== $user->id)return false;
  $access=app(CommunityAccess::class);$data=$notification->data??[];
  if(isset($data['subject_type']) || isset($data['subject_id'])){
   if(!$access->allows($user,(string)($data['subject_type']??''),(int)($data['subject_id']??0)))return false;
  }
  foreach(array_keys(CommunityAccess::SUBJECTS) as $type){
   if(isset($data[$type.'_id']) && !$access->allows($user,$type,(int)$data[$type.'_id']))return false;
  }
  if(preg_match('#^/(clubs|tournaments|decks|matches|challenges|casual-matches)/(\d+)(?:[/?\#]|$)#',$notification->url,$m)){
   $type=['clubs'=>'club','tournaments'=>'tournament','decks'=>'deck','matches'=>'match','challenges'=>'challenge','casual-matches'=>'casual_match'][$m[1]];
   if(!$access->allows($user,$type,(int)$m[2]))return false;
  }
  return true;
 }
 /** Nao lidas que a pessoa ainda pode ver (mesma regra da caixa de entrada): o numero do sino bate com a pagina. */
 public function unreadCount(User $user):int {
  return CommunityNotification::where('user_id',$user->id)->whereNull('read_at')->get()->filter(fn($n)=>$this->canRead($user,$n))->count();
 }
 public function visible(User $user):\Illuminate\Support\Collection {
  return CommunityNotification::where('user_id',$user->id)->latest('id')->cursor()->filter(fn($n)=>$this->canRead($user,$n))->values()->collect();
 }
 /** Categories mute every type under them: muting "match" also mutes "match.submitted". */
 public function muted(string $type,array $muted):bool {
  return in_array($type,$muted,true) || in_array(strtok($type,'.'),$muted,true);
 }
 /** Database-only delivery; key is unique per recipient. No mail is queued. */
 public function send(User $user,string $type,string $title,string $url,string $key,array $data=[]):void {
  $preference=NotificationPreference::where('user_id',$user->id)->first();
  if($preference && (!$preference->enabled || $this->muted($type,$preference->muted_types ?? [])))return;
  // Never render an externally supplied URL as an actionable notification.
  if(!str_starts_with($url,'/') || str_starts_with($url,'//') || str_contains($url,'\\') || preg_match('/[\x00-\x20]/',$url))$url='/notifications';
  CommunityNotification::firstOrCreate(['user_id'=>$user->id,'event_key'=>$key],['type'=>$type,'title'=>$title,'url'=>$url,'data'=>$data]);
 }
}
