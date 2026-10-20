<?php
namespace App\Services\Community;
use App\Models\{User,CommunityActivity};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
class ActivityService {
 /**
  * Stable integration API: record($actor, 'match.confirmed', 'Resultado confirmado',
  * $match, "match:{$match->id}:confirmed:{$revision}").
  * Call inside the domain transaction. Use a stable global event key for retries;
  * never include secrets in title/data. First payload wins. Subject authorization
  * is rechecked on EVERY read (including actor), never snapshotted at publication.
  * Supported subjects: Club, Tournament, GameMatch, Deck, Challenge, CasualMatch.
  */
 public function record(User $actor,string $type,string $title,Model $subject,string $key,array $data=[]):CommunityActivity {
  return CommunityActivity::firstOrCreate(['event_key'=>$key],['actor_id'=>$actor->id,'type'=>$type,'title'=>$title,'subject_type'=>app(CommunityAccess::class)->type($subject),'subject_id'=>$subject->getKey(),'data'=>$data]);
 }
 public function visible(User $user,int $page=1,int $perPage=20):LengthAwarePaginator {
  $access=app(CommunityAccess::class);
  $visible=CommunityActivity::latest('id')->cursor()->filter(fn($event)=>$access->allows($user,$event->subject_type,$event->subject_id))->values()->collect();
  return new LengthAwarePaginator($visible->forPage(max(1,$page),$perPage)->values(),$visible->count(),$perPage,max(1,$page),['path'=>request()->url(),'query'=>request()->query()]);
 }
}
