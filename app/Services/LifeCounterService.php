<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Policies\MatchResultSubmissionPolicy;
use App\Services\Life\LifeTableService;
use App\Services\Realtime\LiveChannels;
use App\Support\LifeColor;
use Illuminate\Support\Str;

/**
 * Monta a configuracao do contador de vida (estilo Lotus) vinculado a uma partida.
 * O contador so le dados e aponta para o endpoint canonico de resultado de cada tipo
 * de partida: a gravacao continua passando pelas mesmas regras/validacoes de antes.
 * Vida e contadores ficam numa mesa compartilhada (LifeTableService): os celulares dos jogadores veem o mesmo placar.
 *
 * Retorno: ['open' => bool, 'resultUrl' => string, ...config para o JS].
 */
class LifeCounterService
{
    public const POISON_LIMIT = 10;

    public const COMMANDER_DAMAGE_LIMIT = 21;

    public function __construct(
        private readonly LifeTableService $tables,
    ) {}

    public function startingLife(?string $format, bool $pod = false): int
    {
        $f = mb_strtolower((string) $format);
        if (str_contains($f, 'brawl')) {
            return $pod ? 30 : 25;
        }
        if (str_contains($f, 'commander') || str_contains($f, 'edh') || $pod) {
            return 40;
        }

        return 20;
    }

    public function forTournament(Tournament $tournament, GameMatch $match, User $user): array
    {
        abort_unless($match->tournament_id === $tournament->id, 404);
        $tournament->loadMissing('players.profile');
        $match->loadMissing(['playerOne.profile', 'playerTwo.profile']);
        $organizer = $user->can('update', $tournament);
        $editable = app(MatchResultService::class)->editable($tournament, $match);

        if ($match->pod_player_ids) {
            $seats = collect($match->pod_player_ids)->map(fn ($id) => $tournament->players->firstWhere('id', (int) $id))->filter()->values();
            $mine = $seats->search(fn ($p) => $p->profile?->account_user_id === $user->id);
            abort_unless($mine !== false || $organizer, 403);
            $life = $this->startingLife($tournament->format, true);

            return $this->config([
                'open' => $match->status === GameMatch::STATUS_PENDING && $editable,
                'mode' => 'pod',
                'type' => 'tournament',
                'id' => $match->id,
                'title' => $tournament->name,
                'subtitle' => 'Mesa Commander · Rodada '.$match->round,
                'format' => $tournament->format,
                'startingLife' => $life,
                'players' => $seats->map(fn ($p) => $this->seat($p->id, $p->displayName(), $p->profile))->all(),
                'meIndex' => $mine === false ? 0 : $mine,
                'submit' => $organizer ? ['kind' => 'pod', 'method' => 'PUT', 'url' => route('matches.update', [$tournament, $match])] : null,
                'resultUrl' => $organizer ? route('tournaments.show', $tournament) : route('matches.mine'),
            ], $user);
        }

        $participant = (new MatchResultSubmissionPolicy)->participate($user, $match);
        abort_unless($participant || $organizer, 403);
        $hasSubmission = MatchResultSubmission::where('match_id', $match->id)->exists();
        $decisive = $match->stage !== GameMatch::STAGE_LEAGUE || in_array($tournament->system, ['single_elimination', 'double_elimination'], true);
        $meIndex = $match->playerTwo?->profile?->account_user_id === $user->id ? 1 : 0;

        return $this->config([
            // Jogador: so enquanto ninguem enviou placar. Organizador: grava direto (mesmo fluxo do painel).
            'open' => $match->status === GameMatch::STATUS_PENDING && $match->player_two_id !== null && $editable && ($participant ? ! $hasSubmission : true),
            'mode' => 'duel',
            'type' => 'tournament',
            'id' => $match->id,
            'title' => $tournament->name,
            'subtitle' => 'Rodada '.$match->round.' · MD3'.($decisive ? ' · sem empate' : ''),
            'format' => $tournament->format,
            'startingLife' => $this->startingLife($tournament->format),
            'bestOf' => 3,
            'winsNeeded' => 2,
            // Liga aceita 1x1 / 1x0 por tempo; o servidor valida o placar final.
            'earlyEnd' => true,
            'players' => [
                $this->seat($match->player_one_id, $match->playerOne?->displayName(), $match->playerOne?->profile),
                $this->seat($match->player_two_id, $match->playerTwo?->displayName(), $match->playerTwo?->profile),
            ],
            'meIndex' => $meIndex,
            'submit' => $participant
                ? ['kind' => 'tournament', 'method' => 'POST', 'url' => route('matches.submissions.store', [$tournament, $match])]
                : ['kind' => 'tournament_admin', 'method' => 'PUT', 'url' => route('matches.update', [$tournament, $match])],
            'resultUrl' => $participant ? route('matches.mine') : route('tournaments.show', $tournament),
        ], $user);
    }

    public function forRanked(RankedMatch $match, User $user): array
    {
        $match->loadMissing(['season.formats', 'playerOne', 'playerTwo']);
        abort_unless($match->involvesUser($user), 403);
        $allowDraws = (bool) ($match->season?->formatConfig($match->format)?->allow_draws ?? false);

        return $this->config([
            'open' => in_array($match->status, ['pending', 'disputed'], true) && in_array($match->season?->status, ['active', 'closing'], true),
            'mode' => 'duel',
            'type' => 'ranked',
            'id' => $match->id,
            'title' => 'Ranqueada · '.$match->format,
            'subtitle' => 'MD'.$match->best_of.' · '.$match->season?->name,
            'format' => $match->format,
            'startingLife' => $this->startingLife($match->format),
            'bestOf' => (int) $match->best_of,
            'winsNeeded' => $match->winsNeeded(),
            'earlyEnd' => $allowDraws,
            'players' => [
                $this->seat($match->player_one_profile_id, $match->playerOne?->displayName(), $match->playerOne),
                $this->seat($match->player_two_profile_id, $match->playerTwo?->displayName(), $match->playerTwo),
            ],
            'meIndex' => $match->playerTwo?->account_user_id === $user->id ? 1 : 0,
            'submit' => ['kind' => 'ranked', 'method' => 'POST', 'url' => route('ranked.matches.results', $match)],
            'resultUrl' => route('ranked.matches.show', $match),
        ], $user);
    }

    public function forChallenge(Challenge $challenge, User $user): array
    {
        $challenge->loadMissing(['challenger', 'opponent', 'casualMatch']);
        abort_unless($challenge->involves($user), 403);
        $match = $challenge->casualMatch;

        return $this->config([
            'open' => ! $challenge->is_ranked && $challenge->status === 'accepted' && $match && in_array($match->status, ['pending', 'disputed'], true),
            'mode' => 'duel',
            'type' => 'challenge',
            'id' => $challenge->id,
            'title' => 'Desafio casual · '.$challenge->format,
            'subtitle' => 'MD'.$challenge->best_of,
            'format' => $challenge->format,
            'startingLife' => $this->startingLife($challenge->format),
            'bestOf' => (int) $challenge->best_of,
            'winsNeeded' => intdiv((int) $challenge->best_of, 2) + 1,
            'earlyEnd' => false,
            'players' => [
                $this->seat($challenge->challenger_id, $challenge->challenger?->displayName(), $challenge->challenger),
                $this->seat($challenge->opponent_id, $challenge->opponent?->displayName(), $challenge->opponent),
            ],
            'meIndex' => $challenge->opponent?->account_user_id === $user->id ? 1 : 0,
            'submit' => ['kind' => 'challenge', 'method' => 'POST', 'url' => route('challenges.report', $challenge)],
            'resultUrl' => route('challenges.show', $challenge),
        ], $user);
    }

    /** Um lugar na mesa: id, nome e a cor que o dono da conta escolheu nas preferências (null = automática). */
    /** Um lugar na mesa: id, nome, a cor que o dono da conta escolheu (null = automatica) e a conta (quem mandou cada jogada). */
    private function seat(?int $id, ?string $name, ?PlayerProfile $profile): array
    {
        return ['id' => $id, 'name' => $name, 'color' => LifeColor::of($profile?->account), 'user' => $profile?->account_user_id];
    }

    private function config(array $config, User $user): array
    {
        // Mesa compartilhada: so quando o contador abre (partida encerrada nao cria mesa).
        $table = null;
        if ($config['open']) {
            $table = $this->tables->forSubject($config['type'], (int) $config['id'], count($config['players']));
        }
        $config['sync'] = $table ? [
            'table' => $table->id,
            'url' => route('life.tables.sync', $table),
            'opsUrl' => route('life.tables.ops', $table),
            'channel' => LiveChannels::life($table->id),
            'me' => $user->id,
        ] : null;

        return $config + [
            'bestOf' => 1,
            'winsNeeded' => 1,
            'earlyEnd' => false,
            'commanderDamage' => $config['startingLife'] >= 40,
            'poisonLimit' => self::POISON_LIMIT,
            'commanderLimit' => self::COMMANDER_DAMAGE_LIMIT,
            'idempotencyKey' => (string) Str::uuid(),
            // Estado salvo no aparelho (copia da mesa + jogadas ainda nao enviadas): um por mesa e por conta.
            'storageKey' => $table ? LifeTableService::storageKey($table, $user) : 'ml-life:'.$config['type'].':'.$config['id'].':'.$user->id,
        ];
    }
}
