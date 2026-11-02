<?php

namespace App\Services;

use App\Models\CasualMatch;
use App\Models\Challenge;
use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\RankedMatch;
use App\Models\RankedResultSubmission;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * "Versão" de uma tela: um hash curto do que ela mostra. A página guarda a versão com que foi montada e
 * pergunta de tempos em tempos (live-refresh.js); quando muda, a tela se atualiza sozinha.
 *
 * Escopos (vários separados por vírgula):
 *  - me: tudo que é do usuário (notificações, desafios, Ranked, inscrições e partidas de torneio)
 *  - challenge:{id} / ranked:{id}: um desafio ou uma partida ranqueada em que a pessoa está
 *  - tournament:{id}: um torneio que a pessoa pode ver; arena:{slug}: a página pública do torneio
 */
class LiveVersionService
{
    public const MAX_SCOPES = 3;

    /** @throws HttpExceptionInterface escopo inválido (404) ou sem permissão (401/403) */
    public function version(?User $user, string $scopes): string
    {
        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $scopes)))));
        abort_if($list === [] || count($list) > self::MAX_SCOPES, 404);

        return substr(sha1(json_encode(array_map(fn (string $scope) => $this->scope($user, $scope), $list), JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** Para o layout: null quando o escopo não vale para quem está vendo (a tela só não se atualiza). */
    public function versionOrNull(?User $user, string $scopes): ?string
    {
        try {
            return $this->version($user, $scopes);
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    private function scope(?User $user, string $scope): array
    {
        [$type, $key] = array_pad(explode(':', $scope, 2), 2, '');

        if ($type === 'arena') {
            return $this->tournament(Tournament::query()->where('public_slug', $key)->firstOrFail());
        }
        abort_unless($user, 401);

        return match ($type) {
            'me' => $this->user($user),
            'challenge' => $this->challenge($user, (int) $key),
            'ranked' => $this->ranked($user, (int) $key),
            'tournament' => $this->tournamentFor($user, (int) $key),
            default => abort(404),
        };
    }

    private function user(User $user): array
    {
        $profileId = $user->playerProfile?->id;
        $playerIds = Player::query()->whereHas('profile', fn ($q) => $q->where('account_user_id', $user->id))->pluck('id');
        $challengeIds = Challenge::query()->where(fn ($q) => $q->where('challenger_id', $profileId)->orWhere('opponent_id', $profileId))->pluck('id');
        $matches = GameMatch::query()->where(fn ($q) => $q->whereIn('player_one_id', $playerIds)->orWhereIn('player_two_id', $playerIds));

        return [
            'notifications' => $this->stamp(CommunityNotification::query()->where('user_id', $user->id), 'max(id)', 'count(read_at)'),
            'challenges' => $this->stamp(Challenge::query()->whereKey($challengeIds)),
            'casual' => $this->stamp(CasualMatch::query()->whereIn('challenge_id', $challengeIds)),
            'ranked' => $profileId ? $this->stamp(RankedMatch::query()->where(fn ($q) => $q->where('player_one_profile_id', $profileId)->orWhere('player_two_profile_id', $profileId))) : null,
            'entries' => $this->stamp(Player::query()->whereKey($playerIds)),
            'tournaments' => $this->stamp(Tournament::query()->whereHas('players', fn ($q) => $q->whereKey($playerIds))),
            'matches' => $this->stamp(clone $matches),
            'submissions' => $this->stamp(MatchResultSubmission::query()->whereIn('match_id', $matches->select('id'))),
        ];
    }

    private function challenge(User $user, int $id): array
    {
        $challenge = Challenge::query()->with(['challenger', 'opponent'])->findOrFail($id);
        abort_unless($challenge->involves($user), 403);
        $ranked = RankedMatch::query()->where(['source_type' => 'challenge', 'source_id' => $challenge->id]);

        return [
            'challenge' => [$challenge->status, (string) $challenge->updated_at],
            'casual' => $this->stamp(CasualMatch::query()->where('challenge_id', $challenge->id), 'max(revision)'),
            'ranked' => $this->stamp(clone $ranked),
            'ranked_submissions' => $this->stamp(RankedResultSubmission::query()->whereIn('ranked_match_id', $ranked->select('id'))),
        ];
    }

    private function ranked(User $user, int $id): array
    {
        $match = RankedMatch::query()->findOrFail($id);
        abort_unless($user->can('view', $match), 403);

        return [
            'match' => [$match->status, (string) $match->updated_at],
            'submissions' => $this->stamp(RankedResultSubmission::query()->where('ranked_match_id', $match->id)),
        ];
    }

    private function tournamentFor(User $user, int $id): array
    {
        $tournament = Tournament::query()->findOrFail($id);
        abort_unless($user->can('view', $tournament), 403);

        return $this->tournament($tournament);
    }

    private function tournament(Tournament $tournament): array
    {
        $matches = GameMatch::query()->where('tournament_id', $tournament->id);

        return [
            'tournament' => [$tournament->status, (string) $tournament->updated_at],
            'players' => $this->stamp(Player::query()->where('tournament_id', $tournament->id)),
            'matches' => $this->stamp(clone $matches),
            'submissions' => $this->stamp(MatchResultSubmission::query()->whereIn('match_id', $matches->select('id'))),
        ];
    }

    /** Quantidade, última alteração e o que mais a tabela precisar, numa consulta só. */
    private function stamp(Builder $query, string ...$extra): array
    {
        $columns = ['count(*) as total', 'max(updated_at) as touched'];
        foreach ($extra as $i => $expression) $columns[] = "{$expression} as extra{$i}";

        return array_values((array) $query->toBase()->selectRaw(implode(', ', $columns))->first());
    }
}
