<?php
namespace App\Services;
use App\Models\{Challenge, CasualMatch, PlayerProfile, User};
use App\Services\Community\CommunityEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChallengeService {
    private function require(bool $condition, string $message): void {
        if (! $condition) throw ValidationException::withMessages(['challenge' => $message]);
    }
    public function create(User $actor, PlayerProfile $opponent, string $format, int $bestOf, CarbonInterface $expires, bool $ranked = false): Challenge {
        $this->require($opponent->account_user_id !== null && $opponent->account_user_id !== $actor->id && $actor->friendProfiles()->whereKey($opponent->id)->wherePivot('status', 'accepted')->exists(), 'Escolha um amigo com conta.');
        $this->require(in_array($bestOf, [1, 3, 5], true) && mb_strlen(trim($format)) > 0 && mb_strlen($format) <= 60 && $expires->isFuture(), 'Formato, melhor de ou validade inválidos.');
        if ($ranked) {
            // Os dois concordam ANTES da partida: o convite ja diz que e ranqueado.
            $season = app(Ranked\SeasonService::class)->activeFor(trim($format));
            $this->require($season !== null, 'Não há temporada ranqueada ativa para '.trim($format).'.');
            $error = app(Ranked\RankedMatchService::class)->eligibilityError($season, trim($format), $actor->ensurePlayerProfile(), $opponent);
            $this->require($error === null, (string) $error);
        }
        $challenge = Challenge::create(['challenger_id' => $actor->ensurePlayerProfile()->id, 'opponent_id' => $opponent->id, 'format' => trim($format), 'best_of' => $bestOf, 'expires_at' => $expires, 'status' => 'pending', 'is_ranked' => $ranked]);
        app(CommunityEvents::class)->challengeCreated($challenge);
        return $challenge;
    }
    public function expire(): int {
        return Challenge::query()->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }
    public function report(Challenge $challenge, User $actor, int $one, int $two, array $order = []): CasualMatch {
        $match = DB::transaction(function () use ($challenge, $actor, $one, $two, $order) {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            abort_unless($challenge->involves($actor), 403);
            $this->require($challenge->status === 'accepted', 'O desafio não está em andamento.');
            $match = $challenge->casualMatch()->lockForUpdate()->firstOrFail();
            $this->require(in_array($match->status, ['pending', 'disputed'], true), 'Aguarde a revisão do placar.');
            $target = intdiv($challenge->best_of, 2) + 1;
            $this->require(min($one, $two) >= 0 && max($one, $two) === $target && min($one, $two) < $target, 'Informe um placar final válido para esta série.');
            if ($order !== []) {
                $this->require(count($order) === $one + $two, 'A ordem deve conter todos os games.');
                $counts = [1 => 0, 2 => 0];
                foreach ($order as $winner) {
                    $this->require(in_array($winner, [1, 2], true) && max($counts) < $target, 'Ordem de games impossível.');
                    $counts[$winner]++;
                }
                $this->require($counts[1] === $one && $counts[2] === $two, 'A ordem não corresponde ao placar.');
            }
            $match->update(['status' => 'reported', 'challenger_games' => $one, 'opponent_games' => $two, 'game_order' => $order ?: null, 'reported_by' => $actor->id, 'dispute_reason' => null, 'revision' => $match->revision + 1]);
            return $match;
        });
        app(CommunityEvents::class)->challengeReported($challenge->fresh(), $actor, (int) $match->revision);
        return $match;
    }
    public function review(Challenge $challenge, User $actor, string $action, int $revision, ?string $reason = null): CasualMatch {
        $match = DB::transaction(function () use ($challenge, $actor, $action, $revision, $reason) {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            abort_unless($challenge->involves($actor), 403);
            $match = $challenge->casualMatch()->lockForUpdate()->firstOrFail();
            abort_if($match->reported_by === $actor->id, 403);
            $this->require($challenge->status === 'accepted' && $match->status === 'reported' && $match->revision === $revision, 'O placar foi alterado ou já revisado. Atualize a página.');
            $this->require(in_array($action, ['confirm', 'dispute'], true), 'Ação inválida.');
            if ($action === 'dispute') {
                $this->require(mb_strlen(trim($reason ?? '')) > 0 && mb_strlen($reason) <= 1000, 'Explique a contestação (até 1000 caracteres).');
                $match->update(['status' => 'disputed', 'dispute_reason' => trim($reason)]);
            } else {
                $match->update(['status' => 'confirmed', 'confirmed_at' => now()]);
                $challenge->update(['status' => 'completed']);
                foreach ([$challenge->challenger, $challenge->opponent] as $profile) app(AchievementService::class)->sync($profile);
            }
            return $match;
        });
        app(CommunityEvents::class)->challengeReviewed($challenge->fresh(), $actor, $action, $revision);
        return $match;
    }
    public function transition(Challenge $challenge, User $actor, string $action): Challenge {
        $challenge = DB::transaction(function () use ($challenge, $actor, $action) {
            $challenge = Challenge::query()->lockForUpdate()->findOrFail($challenge->id);
            abort_unless($challenge->involves($actor), 403);
            $this->require($challenge->status === 'pending' && $challenge->expires_at->isFuture(), 'Este convite não está mais pendente.');
            if (in_array($action, ['accept', 'decline'], true)) abort_unless($challenge->opponent->account_user_id === $actor->id, 403);
            elseif ($action === 'cancel') abort_unless($challenge->challenger->account_user_id === $actor->id, 403);
            else $this->require(false, 'Ação inválida.');
            $challenge->update(['status' => match ($action) { 'accept' => 'accepted', 'decline' => 'declined', 'cancel' => 'cancelled' }]);
            if ($action === 'accept' && $challenge->is_ranked) {
                $season = app(Ranked\SeasonService::class)->activeFor($challenge->format);
                $this->require($season !== null, 'A temporada ranqueada deste formato não está mais ativa.');
                app(Ranked\RankedMatchService::class)->create($season, $challenge->format, $challenge->challenger, $challenge->opponent, 'challenge', $challenge->id, $challenge->best_of);
            } elseif ($action === 'accept') {
                $challenge->casualMatch()->create(['status' => 'pending']);
            }
            return $challenge;
        });
        app(CommunityEvents::class)->challengeTransition($challenge, $action);
        return $challenge;
    }
}
