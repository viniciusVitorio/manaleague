<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Challenge extends Model {
    /** Dias que o adversário tem para confirmar ou contestar o placar; depois disso ele é confirmado sozinho. */
    public const CONFIRM_DAYS = 7;
    protected $guarded = ['id'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'best_of' => 'integer', 'is_ranked' => 'boolean']; }
    public function statusLabel(): string { return match ($this->status) { 'pending' => 'Convite pendente', 'accepted' => 'Em andamento', 'declined' => 'Recusado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado', 'completed' => 'Concluído', default => 'Indisponível' }; }
    public function challenger(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'challenger_id'); }
    public function opponent(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'opponent_id'); }
    public function casualMatch(): HasOne { return $this->hasOne(CasualMatch::class); }
    public function involves(User $user): bool { return in_array($user->id, [$this->challenger->account_user_id, $this->opponent->account_user_id], true); }

    /** Em andamento (convite ou série sem placar confirmado): ainda pede uma ação dos jogadores. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'accepted'], true);
    }

    /**
     * Onde o desafio mora: em andamento, o cartão dele em Desafios (com as ações); encerrado, o resumo da partida em
     * Minhas partidas. Não existe mais página própria do desafio (/challenges/{id} só redireciona para cá).
     */
    public function url(): string
    {
        return $this->isOpen() ? route('challenges.index').'#desafio-'.$this->id : route('matches.detail', ['challenge', $this->id]);
    }
}
