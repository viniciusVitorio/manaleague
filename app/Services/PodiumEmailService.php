<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentPodiumEmail;
use App\Models\TournamentPrize;
use App\Models\User;
use App\Notifications\TournamentPodiumNotification;
use App\Services\Community\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * E-mail para quem subiu ao pódio, com o resultado da pessoa no torneio.
 *
 * Produção não tem worker de fila: o envio acontece logo depois da resposta, e o que falhar é tentado de novo pela
 * tarefa diária (tournaments:podium-emails). A linha em tournament_podium_emails é a garantia contra duplicidade.
 */
class PodiumEmailService
{
    /** Primeira tentativa ao encerrar; as outras vêm da tarefa diária. */
    public const MAX_ATTEMPTS = 3;

    /** Depois disso o aviso perdeu o sentido e não é mais tentado. */
    public const RETRY_DAYS = 3;

    private const MEDALS = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

    private const NOTIFICATION_TYPE = 'tournament.podium';

    public function __construct(
        private readonly StandingsService $standings,
        private readonly PlayerStatsService $stats,
        private readonly PrizeService $prizes,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Registra os e-mails do pódio atual e agenda o envio para depois da resposta. Chamar depois do commit.
     * Pode repetir: uma correção da final só gera e-mail para quem mudou de colocação. Falha aqui não atrapalha o placar.
     */
    public function schedule(Tournament $tournament): void
    {
        rescue(function () use ($tournament): void {
            $t = Tournament::query()->find($tournament->id);
            if (! $t || $t->status !== Tournament::STATUS_FINISHED || ! $t->finished_at) {
                return;
            }

            $podium = $this->podium($t);
            $users = User::query()->whereIn('id', $podium->keys())->get()->filter(fn (User $user) => $this->eligible($user));
            $now = now();
            foreach ($users as $user) {
                TournamentPodiumEmail::query()->insertOrIgnore([
                    'tournament_id' => $t->id, 'user_id' => $user->id, 'placement' => $podium[$user->id]['podium'],
                    'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            app()->terminating(fn () => rescue(fn () => $this->deliverPending($t->id)));
        });
    }

    /**
     * Envia o que está pendente, de um torneio ou de todos.
     *
     * @return array{sent: int, failed: int}
     */
    public function deliverPending(?int $tournamentId = null): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $pending = TournamentPodiumEmail::query()->with(['tournament', 'user'])
            ->whereNull('sent_at')->whereNull('claimed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>=', now()->subDays(self::RETRY_DAYS))
            ->when($tournamentId, fn ($query) => $query->where('tournament_id', $tournamentId))
            ->orderBy('id')->get();

        foreach ($pending->groupBy('tournament_id') as $emails) {
            $tournament = $emails->first()->tournament;
            $podium = $this->podium($tournament);
            $records = $this->stats->tournamentRecords($tournament);
            $prizes = $this->prizes->wonByAccount($tournament);
            $players = $tournament->players()->count();

            foreach ($emails as $email) {
                $place = $podium->get($email->user_id);
                // A final pode ter sido corrigida antes do envio, e a pessoa pode ter desligado o aviso.
                if (! $place || $place['podium'] !== $email->placement || ! $this->eligible($email->user)) {
                    $email->delete();

                    continue;
                }

                $summary = $this->summary($tournament, $email->placement, $records[$place['player']->id], $prizes->get($email->user_id, []), $players);
                $outcome = $this->send($email, $summary);
                if ($outcome !== null) {
                    $result[$outcome]++;
                }
            }
        }

        return $result;
    }

    /**
     * Quem pega a linha primeiro envia; sem isso, o encerramento e a tarefa diária poderiam mandar o mesmo e-mail.
     * Se o processo morrer durante o envio, a linha fica presa de propósito: não dá para saber se o e-mail saiu,
     * e perder um aviso é melhor do que mandar duas vezes.
     *
     * @param  array<string, mixed>  $summary
     * @return 'sent'|'failed'|null null quando outro processo já pegou
     */
    private function send(TournamentPodiumEmail $email, array $summary): ?string
    {
        $claimed = TournamentPodiumEmail::query()->whereKey($email->id)->whereNull('sent_at')->whereNull('claimed_at')
            ->increment('attempts', 1, ['claimed_at' => now()]);
        if (! $claimed) {
            return null;
        }

        try {
            $email->user->notify(new TournamentPodiumNotification($summary));
            $email->forceFill(['sent_at' => now()])->save();

            return 'sent';
        } catch (Throwable $exception) {
            // Só a classe: o texto da exceção pode trazer o endereço da pessoa ou dados do provedor.
            Log::warning('tournament.podium_mail_failed', ['tournament_id' => $email->tournament_id, 'user_id' => $email->user_id, 'exception' => $exception::class]);
            TournamentPodiumEmail::query()->whereKey($email->id)->update(['claimed_at' => null]);

            return 'failed';
        }
    }

    /** Linhas do pódio com conta, por id de usuário. */
    private function podium(Tournament $tournament): Collection
    {
        return $this->standings->withPodium($tournament, $this->standings->for($tournament))
            ->filter(fn (array $row) => ! empty($row['podium']) && $row['player']->profile?->account_user_id)
            ->keyBy(fn (array $row) => $row['player']->profile->account_user_id);
    }

    /** Endereço ainda não confirmado não recebe nada, e quem desligou o e-mail (geral ou de Torneios) também não. */
    private function eligible(?User $user): bool
    {
        return $user !== null
            && (! $user->email_verification_required || $user->hasVerifiedEmail())
            && $this->notifications->wantsEmail($user, self::NOTIFICATION_TYPE);
    }

    /**
     * @param  array<string, mixed>  $record  recorde de PlayerStatsService::tournamentRecords
     * @param  list<string>  $prizes
     * @return array<string, mixed>
     */
    private function summary(Tournament $tournament, int $placement, array $record, array $prizes, int $players): array
    {
        return [
            'tournament' => Str::squish($tournament->name),
            'format' => $tournament->format,
            'date' => $tournament->tournament_date?->timezone(config('app.timezone'))->format('d/m/Y'),
            'players' => $players,
            'placement' => $placement,
            'placement_label' => TournamentPrize::PLACEMENTS[$placement],
            'medal' => self::MEDALS[$placement],
            'played' => $record['played'],
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'draws' => $record['draws'],
            'winrate' => $record['winrate'],
            // Mesa de Commander (pods ou eliminação por pods) tem colocação, não placar em games.
            'games' => in_array($tournament->system, ['commander_pods', 'pod_elimination'], true) ? null : $record['games_for'].'–'.$record['games_against'],
            'prizes' => $prizes,
            'url' => route('arena.public', $tournament->public_slug),
            'prizes_url' => route('prizes.index'),
            'preferences_url' => route('profiles.edit').'#notificacoes',
        ];
    }
}
