<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Challenge extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'best_of' => 'integer', 'is_ranked' => 'boolean']; }
    public function statusLabel(): string { return match ($this->status) { 'pending' => 'Convite pendente', 'accepted' => 'Em andamento', 'declined' => 'Recusado', 'cancelled' => 'Cancelado', 'expired' => 'Expirado', 'completed' => 'Concluído', default => 'Indisponível' }; }
    public function challenger(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'challenger_id'); }
    public function opponent(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'opponent_id'); }
    public function casualMatch(): HasOne { return $this->hasOne(CasualMatch::class); }
    public function involves(User $user): bool { return in_array($user->id, [$this->challenger->account_user_id, $this->opponent->account_user_id], true); }
}
