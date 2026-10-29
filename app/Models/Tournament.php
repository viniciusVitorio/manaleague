<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use HasFactory;

    public const STATUS_SETUP = 'setup';

    public const STATUS_LEAGUE = 'league';

    public const STATUS_FINALS = 'finals';

    public const STATUS_FINISHED = 'finished';

    public const SYSTEMS = ['round_robin' => 'Todos contra todos', 'swiss' => 'Suíço', 'single_elimination' => 'Eliminação simples', 'double_elimination' => 'Dupla eliminação', 'commander_pods' => 'Pods Commander'];

    /** Regras do formato: banidas não entram. Personalizadas: o organizador decide (e pode escrever regras da casa). */
    public const RULES_STANDARD = 'standard';

    public const RULES_CUSTOM = 'custom';

    protected $attributes = ['system' => 'round_robin', 'rules' => self::RULES_STANDARD, 'allow_banned' => false];

    protected $fillable = ['system', 'ranked', 'round_limit', 'name', 'format', 'tournament_date', 'max_players', 'status', 'public_token', 'public_slug', 'invite_token', 'rules', 'allow_banned', 'house_rules'];

    protected function casts(): array
    {
        return ['tournament_date' => 'date', 'round_limit' => 'integer', 'ranked' => 'boolean', 'allow_banned' => 'boolean', 'round_ends_at' => 'datetime', 'round_minutes' => 'integer'];
    }

    /** Decks com cartas banidas no formato ficam de fora (inscrição, troca de deck, check-in e início). */
    public function blocksBannedCards(): bool
    {
        return ! $this->allow_banned && \App\Services\Decks\BanListService::covers($this->format);
    }

    /** Resumo das regras para as páginas do torneio. */
    public function rulesLabel(): string
    {
        if ($this->rules === self::RULES_CUSTOM) {
            return 'Regras personalizadas · '.($this->allow_banned ? 'cartas banidas liberadas' : 'sem cartas banidas');
        }

        return \App\Services\Decks\BanListService::covers($this->format) ? 'Regras padrão do '.$this->format.' · sem cartas banidas' : 'Regras padrão do '.$this->format;
    }

    /** Cronômetro da rodada atual em andamento (definido pelo organizador). */
    public function roundTimerRunning(): bool
    {
        return $this->round_ends_at !== null && in_array($this->status, [self::STATUS_LEAGUE, self::STATUS_FINALS], true);
    }

    /** Sugestão para "Repetir torneio": "Liga #4" vira "Liga #5"; a data é a semana seguinte, nunca no passado. */
    public function nextEditionDefaults(): array
    {
        $name = preg_replace_callback('/#(\d+)$/', fn ($m) => '#'.((int) $m[1] + 1), $this->name);
        $date = $this->tournament_date?->copy()->addWeek() ?? today();
        while ($date->lt(today())) $date->addWeek();

        return ['name' => $name, 'date' => $date->toDateString()];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class)->orderBy('name');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
