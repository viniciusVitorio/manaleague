<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Policies\MatchResultSubmissionPolicy;
use Illuminate\Support\Str;

/**
 * Monta a configuracao do contador de vida (estilo Lotus) vinculado a uma partida.
 * O contador so le dados e aponta para o endpoint canonico de resultado de cada tipo
 * de partida: a gravacao continua passando pelas mesmas regras/validacoes de antes.
 *
 * Retorno: ['open' => bool, 'resultUrl' => string, ...config para o JS].
 */
class LifeCounterService
{
    public const POISON_LIMIT = 10;

    public const COMMANDER_DAMAGE_LIMIT = 21;

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
                'players' => $seats->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
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
                ['id' => $match->player_one_id, 'name' => $match->playerOne?->name],
                ['id' => $match->player_two_id, 'name' => $match->playerTwo?->name],
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
                ['id' => $match->player_one_profile_id, 'name' => $match->playerOne?->nickname],
                ['id' => $match->player_two_profile_id, 'name' => $match->playerTwo?->nickname],
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
                ['id' => $challenge->challenger_id, 'name' => $challenge->challenger?->nickname],
                ['id' => $challenge->opponent_id, 'name' => $challenge->opponent?->nickname],
            ],
            'meIndex' => $challenge->opponent?->account_user_id === $user->id ? 1 : 0,
            'submit' => ['kind' => 'challenge', 'method' => 'POST', 'url' => route('challenges.report', $challenge)],
            'resultUrl' => route('challenges.show', $challenge),
        ], $user);
    }

    private function config(array $config, User $user): array
    {
        return $config + [
            'bestOf' => 1,
            'winsNeeded' => 1,
            'earlyEnd' => false,
            'commanderDamage' => $config['startingLife'] >= 40,
            'poisonLimit' => self::POISON_LIMIT,
            'commanderLimit' => self::COMMANDER_DAMAGE_LIMIT,
            'idempotencyKey' => (string) Str::uuid(),
            // Estado salvo no aparelho: um por partida e por conta (o celular fica na mesa).
            'storageKey' => 'ml-life:'.$config['type'].':'.$config['id'].':'.$user->id,
        ];
    }
}
