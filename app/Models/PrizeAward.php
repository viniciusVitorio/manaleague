<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prêmio que um inscrito ganhou num torneio encerrado. Booster: recebido ou a receber. Troféu: pode ir para o destaque do perfil. */
class PrizeAward extends Model
{
    /** Quantos troféus e medalhas cabem no destaque do perfil. */
    public const SHOWCASE_SLOTS = 3;

    protected $fillable = ['player_id', 'player_profile_id'];

    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime', 'showcase_position' => 'integer'];
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(TournamentPrize::class, 'tournament_prize_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'player_profile_id');
    }
}
