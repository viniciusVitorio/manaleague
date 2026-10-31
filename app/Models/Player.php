<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasFactory;

    protected $fillable = ['player_profile_id', 'deck_id', 'name', 'deck_name', 'deck_colors'];

    protected function casts(): array { return ['checked_in_at' => 'datetime']; }

    public function profile(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'player_profile_id'); }

    /** Nome no torneio com o emoji do perfil vinculado, quando houver. */
    public function displayName(): string
    {
        $avatar = $this->player_profile_id ? $this->profile?->avatar : null;

        return ($avatar ? $avatar.' ' : '').$this->name;
    }

    /**
     * Nome do deck vinculado (o atual, se foi renomeado). Sem deck vinculado, quem tem conta fica sem nome:
     * o que foi digitado na inscrição não é um deck. Só o jogador sem conta mantém o nome digitado.
     */
    public function deckLabel(): ?string
    {
        if ($this->deck) return $this->deck->name;

        return $this->profile?->account_user_id ? null : $this->deck_name;
    }

    /** Cores do deck, com a mesma regra do nome (sem deck vinculado, quem tem conta fica sem cores). */
    public function deckColors(): ?string
    {
        if ($this->deck) return $this->deck->colors ?: $this->deck_colors;

        return $this->profile?->account_user_id ? null : $this->deck_colors;
    }

    /** Quem tem conta precisa escolher um deck salvo (menos no Draft). Jogador sem conta não tem decks. */
    public function needsDeck(Tournament $tournament): bool
    {
        return $this->deck_id === null && $tournament->requiresDeck() && $this->profile?->account_user_id !== null;
    }

    public function deck(): BelongsTo { return $this->belongsTo(Deck::class); }
    public function tournament(): BelongsTo { return $this->belongsTo(Tournament::class); }
    public function matchesAsPlayerOne(): HasMany { return $this->hasMany(GameMatch::class, 'player_one_id'); }
    public function matchesAsPlayerTwo(): HasMany { return $this->hasMany(GameMatch::class, 'player_two_id'); }
}
