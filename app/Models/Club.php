<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Club extends Model {
 protected $fillable = ['name','description','privacy'];
 public function members(): BelongsToMany {return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();}
 public function roleFor(User $user): ?string {return $this->members()->whereKey($user->id)->first()?->pivot->role;}
}
