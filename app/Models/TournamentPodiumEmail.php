<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E-mail de pódio de uma pessoa num torneio. Quem cria e envia é o PodiumEmailService. */
class TournamentPodiumEmail extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['placement' => 'integer', 'attempts' => 'integer', 'claimed_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
