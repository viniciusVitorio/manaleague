<?php
namespace App\Policies;
use App\Models\{GameMatch,User,MatchResultSubmission};
class MatchResultSubmissionPolicy {
 public function participate(User $user, GameMatch $match): bool {
  return $match->playerOne?->profile?->account_user_id === $user->id || $match->playerTwo?->profile?->account_user_id === $user->id;
 }
 public function respond(User $user, MatchResultSubmission $submission): bool {
  return $submission->submitted_by !== $user->id && $this->participate($user,$submission->gameMatch);
 }
}
