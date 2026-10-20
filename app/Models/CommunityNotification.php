<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CommunityNotification extends Model {
 protected $guarded = ['id'];
 protected function casts():array {return ['data'=>'array','read_at'=>'datetime'];}
}
