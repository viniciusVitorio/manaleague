<?php

namespace App\Services;

use App\Models\CasualMatch;
use App\Models\MatchResultSubmission;
use App\Models\RankedMatch;
use App\Models\User;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Placares que o adversário enviou e esperam a confirmação do usuário: torneio, desafio casual e Ranked.
 * "Confirmar todos" passa cada um pelo mesmo caminho do botão individual (mesmas regras e travas).
 */
class PendingResultsService
{
    /** @return array{tournament: Collection<int, MatchResultSubmission>, casual: Collection<int, CasualMatch>, ranked: Collection<int, RankedMatch>} */
    public function awaiting(User $user): array
    {
        $profileId = $user->playerProfile?->id;
        $mine = fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('account_user_id', $user->id));

        return [
            'tournament' => MatchResultSubmission::query()->where('status', 'pending')->where('submitted_by', '!=', $user->id)
                ->whereHas('gameMatch', fn ($q) => $q->where('status', 'pending')->where(fn ($q) => $q->whereHas('playerOne', $mine)->orWhereHas('playerTwo', $mine)))
                ->with(['gameMatch.tournament', 'gameMatch.playerOne.profile', 'gameMatch.playerTwo.profile'])->orderBy('id')->get(),
            'casual' => $profileId ? CasualMatch::query()->where('status', 'reported')->where('reported_by', '!=', $user->id)
                ->whereHas('challenge', fn ($q) => $q->where('status', 'accepted')->where(fn ($q) => $q->where('challenger_id', $profileId)->orWhere('opponent_id', $profileId)))
                ->with('challenge.challenger', 'challenge.opponent')->orderBy('id')->get() : collect(),
            'ranked' => $profileId ? RankedMatch::query()->where('status', 'reported')
                ->where(fn ($q) => $q->where('player_one_profile_id', $profileId)->orWhere('player_two_profile_id', $profileId))
                ->whereHas('submissions', fn ($q) => $q->where('status', 'pending')->where('submitted_by', '!=', $user->id))
                ->with(['playerOne', 'playerTwo'])->orderBy('id')->get() : collect(),
        ];
    }

    public function count(User $user): int
    {
        return collect($this->awaiting($user))->sum(fn (Collection $items) => $items->count());
    }

    /**
     * Confirma, do mais antigo para o mais novo, tudo o que espera o usuário. Um placar que mudou,
     * foi contestado ou teve a fase encerrada no meio do caminho fica de fora e não trava os outros.
     *
     * @return array{confirmed: int, skipped: int}
     */
    public function confirmAll(User $user): array
    {
        $confirmed = $skipped = 0;
        $pending = $this->awaiting($user);
        $attempt = function (callable $confirm) use (&$confirmed, &$skipped): void {
            try {
                $confirm();
                $confirmed++;
            } catch (ValidationException|HttpExceptionInterface) {
                $skipped++;
            }
        };

        foreach ($pending['tournament'] as $submission) {
            $attempt(fn () => app(MatchResultSubmissionService::class)->review($submission, $user, 'confirm', []));
        }
        foreach ($pending['casual'] as $match) {
            $attempt(fn () => app(ChallengeService::class)->review($match->challenge, $user, 'confirm', $match->revision));
        }
        foreach ($pending['ranked'] as $match) {
            $attempt(fn () => app(RankedMatchService::class)->confirm($match, $user));
        }

        return compact('confirmed', 'skipped');
    }
}
