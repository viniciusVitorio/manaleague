<?php

namespace App\Models;

use App\Services\AvatarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um prêmio oferecido pelo torneio para uma colocação: boosters (prêmio físico) ou um troféu/medalha (colecionável). */
class TournamentPrize extends Model
{
    public const KIND_BOOSTER = 'booster';

    public const KIND_TROPHY = 'trophy';

    /** Participação: todo inscrito recebe, inclusive quem subiu ao pódio. */
    public const PARTICIPATION = 0;

    /** Na ordem em que aparecem nas telas. */
    public const PLACEMENTS = [1 => '1º lugar', 2 => '2º lugar', 3 => '3º lugar', self::PARTICIPATION => 'Participação'];

    /** Catálogo de peças; a chave é também o nome do ícone. A cor vem da colocação. */
    public const STYLES = ['trophy' => 'Troféu', 'medal' => 'Medalha', 'crown' => 'Coroa', 'shield' => 'Escudo'];

    public const MAX_BOOSTERS = 36;

    public const LABEL_MAX = 60;

    /** Toda arte de troféu vira um quadrado deste lado (px), para o perfil mostrar todas do mesmo jeito. */
    public const IMAGE_SIZE = 512;

    /** Quatro colocações num envio só precisam caber no limite de 8 MB do WAF. */
    public const IMAGE_MAX_KILOBYTES = 1536;

    protected $fillable = ['placement', 'kind', 'quantity', 'style', 'label', 'image_path'];

    protected function casts(): array
    {
        return ['placement' => 'integer', 'quantity' => 'integer'];
    }

    public function isTrophy(): bool
    {
        return $this->kind === self::KIND_TROPHY;
    }

    public function placementLabel(): string
    {
        return self::PLACEMENTS[$this->placement] ?? $this->placement.'º lugar';
    }

    /** Ouro, prata e bronze no pódio; participação fica neutra. */
    public function tone(): string
    {
        return [1 => 'gold', 2 => 'silver', 3 => 'bronze'][$this->placement] ?? 'plain';
    }

    public function icon(): string
    {
        return $this->isTrophy() ? (isset(self::STYLES[$this->style]) ? $this->style : 'trophy') : 'gift';
    }

    /** "3 boosters de Bloomburrow", "Taça Liga de Verão" ou, sem nome escolhido, "Troféu de campeão". */
    public function title(): string
    {
        if (! $this->isTrophy()) {
            return $this->quantity.' '.($this->quantity === 1 ? 'booster' : 'boosters').($this->label ? ' de '.$this->label : '');
        }
        if ($this->label) {
            return $this->label;
        }
        $piece = self::STYLES[$this->style] ?? 'Troféu';

        return $piece.' de '.([1 => 'campeão', 2 => '2º lugar', 3 => '3º lugar'][$this->placement] ?? 'participação');
    }

    /** URL da arte do troféu, ou null (sem arte ou storage fora do ar: as telas caem no ícone). */
    public function imageUrl(): ?string
    {
        return $this->image_path ? app(AvatarService::class)->url($this->image_path) : null;
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(PrizeAward::class);
    }
}
