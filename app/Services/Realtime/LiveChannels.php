<?php

namespace App\Services\Realtime;

use App\Models\CasualMatch;
use App\Models\Challenge;
use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\RankedResultSubmission;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Model;

/**
 * Nome dos canais de tempo real e quais canais cada mudanca de dado avisa.
 * Os escopos sao os mesmos do LiveVersionService (me, challenge:{id}, ranked:{id}, tournament:{id}, arena:{slug}):
 * o canal so diz "mudou", e a pagina confere a versao pelo endpoint que ja autoriza.
 *
 * Canais privados (autorizados em routes/channels.php) levam o prefixo "private-"; a arena publica nao,
 * porque a pagina dela ja e aberta para quem tem o link (o slug e o segredo, como na pagina).
 */
class LiveChannels
{
    public static function user(int $userId): string
    {
        return 'private-user.'.$userId;
    }

    public static function tournament(int $id): string
    {
        return 'private-tournament.'.$id;
    }

    public static function arena(string $slug): string
    {
        return 'arena.'.$slug;
    }

    public static function challenge(int $id): string
    {
        return 'private-challenge.'.$id;
    }

    public static function ranked(int $id): string
    {
        return 'private-ranked.'.$id;
    }

    public static function life(int $tableId): string
    {
        return 'private-life.'.$tableId;
    }

    /** Canal de um escopo do LiveVersionService (null = escopo sem canal). */
    public static function forScope(?int $userId, string $scope): ?string
    {
        [$type, $key] = array_pad(explode(':', $scope, 2), 2, '');

        return match (true) {
            $type === 'arena' && $key !== '' => self::arena($key),
            $userId === null => null,
            $type === 'me' => self::user($userId),
            $type === 'challenge' => self::challenge((int) $key),
            $type === 'ranked' => self::ranked((int) $key),
            $type === 'tournament' => self::tournament((int) $key),
            default => null,
        };
    }

    /** @return list<string> canais que uma mudanca neste registro deve avisar */
    public function affectedBy(Model $model): array
    {
        return array_values(array_unique(match (true) {
            $model instanceof Tournament => $this->forTournament($model, withPlayers: true),
            $model instanceof Player => $this->forPlayer($model),
            $model instanceof GameMatch => $this->forMatch($model),
            $model instanceof MatchResultSubmission => ($match = GameMatch::find($model->match_id)) ? $this->forMatch($match) : [],
            $model instanceof Challenge => $this->forChallenge($model),
            $model instanceof CasualMatch => ($challenge = Challenge::find($model->challenge_id)) ? $this->forChallenge($challenge) : [],
            $model instanceof RankedMatch => $this->forRanked($model),
            $model instanceof RankedResultSubmission => ($ranked = RankedMatch::find($model->ranked_match_id)) ? $this->forRanked($ranked) : [],
            $model instanceof CommunityNotification => [self::user((int) $model->user_id)],
            default => [],
        }));
    }

    /** @return list<string> */
    private function forTournament(Tournament $tournament, bool $withPlayers): array
    {
        $channels = [self::tournament($tournament->id)];
        if ($tournament->public_slug) {
            $channels[] = self::arena($tournament->public_slug);
        }
        if ($withPlayers) {
            // Inicio, virada de fase e fim mudam o painel e "minhas partidas" de todos os inscritos com conta.
            $channels = [...$channels, ...$this->users(Player::query()->where('tournament_id', $tournament->id)->pluck('player_profile_id')->all())];
        }

        return $channels;
    }

    /** @return list<string> */
    private function forPlayer(Player $player): array
    {
        $tournament = Tournament::find($player->tournament_id);

        return [...($tournament ? $this->forTournament($tournament, withPlayers: false) : []), ...$this->users([$player->player_profile_id])];
    }

    /** @return list<string> */
    private function forMatch(GameMatch $match): array
    {
        $tournament = Tournament::find($match->tournament_id);
        $playerIds = array_filter([$match->player_one_id, $match->player_two_id, ...array_map('intval', (array) ($match->pod_player_ids ?? []))]);
        $profiles = $playerIds ? Player::query()->whereKey($playerIds)->pluck('player_profile_id')->all() : [];

        return [...($tournament ? $this->forTournament($tournament, withPlayers: false) : []), ...$this->users($profiles)];
    }

    /** @return list<string> */
    private function forChallenge(Challenge $challenge): array
    {
        return [self::challenge($challenge->id), ...$this->users([$challenge->challenger_id, $challenge->opponent_id])];
    }

    /** @return list<string> */
    private function forRanked(RankedMatch $match): array
    {
        $channels = [self::ranked($match->id), ...$this->users([$match->player_one_profile_id, $match->player_two_profile_id])];
        if ($match->source_type === 'challenge' && $match->source_id) {
            $channels[] = self::challenge((int) $match->source_id);
        }

        return $channels;
    }

    /**
     * Canal pessoal de quem tem conta, a partir dos perfis de jogador.
     *
     * @param  array<int, int|null>  $profileIds
     * @return list<string>
     */
    private function users(array $profileIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $profileIds)));
        if ($ids === []) {
            return [];
        }

        return PlayerProfile::query()->whereKey($ids)->whereNotNull('account_user_id')->pluck('account_user_id')
            ->map(fn ($id) => self::user((int) $id))->all();
    }
}
