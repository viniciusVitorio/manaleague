<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma jogada do contador de vida compartilhado, na ordem do servidor (seq). */
class LifeOp extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['life_table_id', 'seq', 'client_id', 'user_id', 'op'];

    protected function casts(): array
    {
        return ['op' => 'array', 'seq' => 'integer'];
    }
}
