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

    public const SYSTEMS = ['round_robin' => 'Liga (todos contra todos)', 'swiss' => 'Suíço', 'single_elimination' => 'Eliminação simples', 'double_elimination' => 'Dupla eliminação', 'commander_pods' => 'Pods Commander', 'pod_elimination' => 'Eliminação por Pods'];

    /** Uma frase por sistema, para o organizador escolher sem precisar conhecer o nome. */
    public const SYSTEM_HINTS = [
        'round_robin' => 'Todos jogam contra todos. No fim, os melhores fazem a final e a disputa de 3º.',
        'swiss' => 'Rodadas contra quem tem pontuação parecida, sem revanche. Vence quem somar mais pontos.',
        'commander_pods' => 'Mesas de 3 ou 4 a cada rodada, misturadas pela classificação. A colocação na mesa vale pontos.',
        'single_elimination' => 'Chave de mata-mata: perdeu, saiu. Vai até a final.',
        'double_elimination' => 'Só sai quem perde duas vezes: quem perde a primeira cai na repescagem.',
        'pod_elimination' => 'Mesas de 3 ou 4 em fases: os melhores de cada mesa avançam até a mesa final.',
    ];

    /**
     * Tipo do evento, pelo jeito de disputar: campeonato (pontos corridos) ou torneio (mata-mata em chaves).
     * Sai do sistema de competição, então o rótulo nunca contradiz o que acontece na mesa.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}> chave => [rótulo, explicação, sistemas]
     */
    public const KINDS = [
        'championship' => ['Campeonato', 'Pontos corridos: vence quem somar mais pontos.', ['round_robin', 'swiss', 'commander_pods']],
        'tournament' => ['Torneio', 'Mata-mata em chaves: quem perde sai, até a final.', ['single_elimination', 'double_elimination', 'pod_elimination']],
    ];

    /** Regras do formato: banidas não entram. Personalizadas: o organizador decide (e pode escrever regras da casa). */
    public const RULES_STANDARD = 'standard';

    public const RULES_CUSTOM = 'custom';

    protected $attributes = ['system' => 'round_robin', 'rules' => self::RULES_STANDARD, 'allow_banned' => false];

    protected $fillable = ['system', 'ranked', 'round_limit', 'name', 'format', 'tournament_date', 'max_players', 'status', 'public_token', 'public_slug', 'invite_token', 'rules', 'allow_banned', 'house_rules', 'pod_qualifiers', 'pod_random_draw', 'pod_tiebreak', 'pod_consolation'];

    protected function casts(): array
    {
        return ['tournament_date' => 'date', 'round_limit' => 'integer', 'ranked' => 'boolean', 'allow_banned' => 'boolean', 'round_ends_at' => 'datetime', 'round_minutes' => 'integer', 'pod_qualifiers' => 'integer', 'pod_random_draw' => 'boolean', 'pod_consolation' => 'boolean', 'finished_at' => 'datetime'];
    }

    /** O torneio encerra por caminhos diferentes (final, última rodada, chave); a data é marcada num lugar só. */
    protected static function booted(): void
    {
        static::saving(function (Tournament $tournament): void {
            if ($tournament->isDirty('status') && $tournament->status === self::STATUS_FINISHED && ! $tournament->finished_at) {
                $tournament->finished_at = now();
            }
        });
    }

    public static function kindOf(?string $system): string
    {
        return in_array($system, self::KINDS['tournament'][2], true) ? 'tournament' : 'championship';
    }

    /** championship ou tournament. */
    public function kind(): string
    {
        return self::kindOf($this->system);
    }

    /** "Campeonato" ou "Torneio", para selo e título. */
    public function kindLabel(): string
    {
        return self::KINDS[$this->kind()][0];
    }

    /** "campeonato" ou "torneio", no meio da frase. As duas palavras são masculinas: "o", "do", "neste" servem para ambas. */
    public function kindNoun(): string
    {
        return mb_strtolower($this->kindLabel());
    }

    /**
     * Decks com cartas banidas no formato ficam de fora (inscrição, troca de deck, check-in e início).
     * No Pauper, as cartas inválidas (nunca saíram como comum) também: ver BanListService::blockedByDeck.
     */
    public function blocksBannedCards(): bool
    {
        return ! $this->allow_banned && \App\Services\Decks\BanListService::covers($this->format);
    }

    /** No Draft o deck é montado na mesa; nos outros formatos quem tem conta entra com um deck salvo. */
    public function requiresDeck(): bool
    {
        return $this->format !== 'Draft';
    }

    /** Deck salvo aceito na inscrição: do formato do torneio ou sem formato definido. "Outro" aceita qualquer deck. */
    public function acceptsDeck(Deck $deck): bool
    {
        return $this->format === 'Outro' || blank($deck->format) || $deck->format === $this->format;
    }

    /** Inscritos com conta que ainda não escolheram um deck: ficam sem check-in e o torneio não começa. */
    public function playersWithoutDeck(): HasMany
    {
        return $this->players()->whereNull('deck_id')
            ->whereHas('profile', fn ($query) => $query->whereNotNull('account_user_id'))
            ->when(! $this->requiresDeck(), fn ($query) => $query->whereRaw('1 = 0'));
    }

    /** Resumo das regras para as páginas do torneio. */
    public function rulesLabel(): string
    {
        if ($this->rules === self::RULES_CUSTOM) {
            $label = \App\Services\Decks\BanListService::blockedLabel($this->format);

            return 'Regras personalizadas · '.($this->allow_banned ? $label.' liberadas' : 'sem '.$label);
        }

        return \App\Services\Decks\BanListService::covers($this->format)
            ? 'Regras padrão do '.$this->format.' · sem '.\App\Services\Decks\BanListService::blockedLabel($this->format)
            : 'Regras padrão do '.$this->format;
    }

    /** Cronômetro da rodada atual em andamento (definido pelo organizador). */
    public function roundTimerRunning(): bool
    {
        return $this->round_ends_at !== null && in_array($this->status, [self::STATUS_LEAGUE, self::STATUS_FINALS], true);
    }

    /** Rodada que está valendo: a primeira com partida sem resultado (null quando não há nenhuma pendente). */
    public function currentRound(): ?int
    {
        $round = $this->matches()->where('status', GameMatch::STATUS_PENDING)->min('round');

        return $round === null ? null : (int) $round;
    }

    /**
     * Quando o jogador pode confirmar a própria presença: só no dia do evento (no fuso do app).
     * 'open' no dia (ou sem data marcada), 'early' antes, 'late' depois. Desfazer e a marcação do organizador não dependem disso.
     */
    public function playerCheckInWindow(): string
    {
        if (! $this->tournament_date) {
            return 'open';
        }
        $day = $this->tournament_date->toDateString();
        $today = now(config('app.timezone'))->toDateString();

        return $day === $today ? 'open' : ($day > $today ? 'early' : 'late');
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

    public function prizes(): HasMany
    {
        return $this->hasMany(TournamentPrize::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
