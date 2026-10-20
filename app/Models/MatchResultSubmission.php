<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MatchResultSubmission extends Model {
 protected $guarded = ['id'];
 public function gameMatch(): BelongsTo { return $this->belongsTo(GameMatch::class,'match_id'); }
}
