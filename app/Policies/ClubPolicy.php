<?php
namespace App\Policies;
use App\Models\{Club,User};
class ClubPolicy {
 public function view(User $user, Club $club): bool {return $club->privacy === 'public' || $club->roleFor($user) !== null;}
 public function manage(User $user, Club $club): bool {return in_array($club->roleFor($user), ['owner','organizer'],true);}
 public function update(User $user, Club $club): bool {return $club->roleFor($user) === 'owner';}
}
