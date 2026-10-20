<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RankedSeason extends Model
{
    public const STATUSES = ['draft' => 'Rascunho', 'scheduled' => 'Agendada', 'active' => 'Em andamento', 'closing' => 'Encerrando', 'finished' => 'Encerrada'];

    protected $fillable = ['name', 'slug', 'status', 'starts_at', 'ends_at', 'scope', 'club_id', 'rules_version', 'settings', 'finished_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'finished_at' => 'datetime', 'settings' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function formats(): HasMany
    {
        return $this->hasMany(RankedSeasonFormat::class, 'season_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(PlayerRating::class, 'season_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(RankedMatch::class, 'season_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(RatingSeasonSnapshot::class, 'season_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? config('ranked.defaults.'.$key);
    }

    public function formatConfig(string $format): ?RankedSeasonFormat
    {
        return $this->formats->first(fn (RankedSeasonFormat $f) => $f->format === $format && $f->enabled);
    }

    public function acceptsMatches(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
