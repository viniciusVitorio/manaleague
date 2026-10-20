<?php
namespace App\Services\Community;
use App\Models\{Club,User};
use Illuminate\Support\Facades\DB;
class ClubService {
 public function create(User $owner,array $data): Club {
  return DB::transaction(function()use($owner,$data){$club=Club::create($data);$club->members()->attach($owner,['role'=>'owner']);return $club;});
 }
}
