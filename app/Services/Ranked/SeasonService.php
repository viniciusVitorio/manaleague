<?php

namespace App\Services\Ranked;

use App\Models\Club;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** draft -> scheduled -> active -> closing -> finished. Datas em UTC, exibicao em America/Sao_Paulo. */
class SeasonService
{
    public function __construct(private readonly LeaderboardService $leaderboard, private readonly RankedNotifier $notifier) {}

    public function create(User $admin, array $data): RankedSeason
    {
        $formats = array_values(array_intersect(config('ranked.formats'), $data['formats'] ?? []));
        $this->fail($formats === [], 'formats', 'Escolha ao menos um formato 1v1.');
        $this->fail($data['ends_at']->lessThanOrEqualTo($data['starts_at']), 'ends_at', 'O fim precisa ser depois do início.');
        $this->fail(($data['scope'] ?? 'global') === 'club' && empty($data['club_id']), 'club_id', 'Escolha o clube da temporada.');

        return DB::transaction(function () use ($admin, $data, $formats) {
            $season = RankedSeason::create([
                'name' => $data['name'], 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
                'status' => 'draft', 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                'scope' => $data['scope'] ?? 'global', 'club_id' => ($data['scope'] ?? 'global') === 'club' ? $data['club_id'] : null,
                'rules_version' => config('ranked.rules_version'), 'created_by' => $admin->id,
                'settings' => array_intersect_key($data['settings'] ?? [], config('ranked.defaults')) ?: null,
            ]);
            foreach ($formats as $format) {
                $season->formats()->create(['format' => $format, 'k_factor' => (int) ($data['k_factor'] ?? config('ranked.defaults.k_factor')), 'starting_rating' => (int) config('ranked.defaults.starting_rating'), 'allow_draws' => (bool) ($data['allow_draws'] ?? true)]);
            }

            return $season;
        });
    }

    /** Publica a temporada; impede duas temporadas sobrepostas no mesmo escopo e formato. */
    public function schedule(RankedSeason $season): RankedSeason
    {
        return DB::transaction(function () use ($season) {
            $season = RankedSeason::query()->with('formats')->lockForUpdate()->findOrFail($season->id);
            $this->fail($season->status !== 'draft', 'season', 'Somente rascunhos podem ser publicados.');
            $formats = $season->formats->where('enabled', true)->pluck('format');
            $conflict = RankedSeason::query()->whereKeyNot($season->id)->whereIn('status', ['scheduled', 'active', 'closing'])
                ->where('scope', $season->scope)->where('club_id', $season->club_id)
                ->where('starts_at', '<', $season->ends_at)->where('ends_at', '>', $season->starts_at)
                ->whereHas('formats', fn ($q) => $q->where('enabled', true)->whereIn('format', $formats))->first();
            $this->fail($conflict !== null, 'season', "Já existe a temporada {$conflict?->name} no mesmo escopo, formato e período.");
            $season->update(['status' => 'scheduled']);
            $this->refresh();

            return $season->fresh();
        });
    }

    /** Antecipa o fim: nenhuma partida nova pontua depois daqui. */
    public function close(RankedSeason $season): void
    {
        DB::transaction(function () use ($season) {
            $season = RankedSeason::query()->lockForUpdate()->findOrFail($season->id);
            $this->fail($season->status !== 'active', 'season', 'Somente temporadas em andamento podem ser encerradas.');
            $season->update(['status' => 'closing', 'ends_at' => now()]);
        });
        $this->refresh();
    }

    /** Transicoes preguicosas por data (chamado nas paginas e em `ranked:tick`). */
    public function refresh(?CarbonInterface $now = null): void
    {
        $now ??= now();

        RankedSeason::query()->where('status', 'scheduled')->where('starts_at', '<=', $now)->update(['status' => 'active']);
        RankedSeason::query()->where('status', 'active')->where('ends_at', '<=', $now)->update(['status' => 'closing']);
        foreach (RankedSeason::query()->where('status', 'closing')->get() as $season) {
            $open = RankedMatch::query()->where('season_id', $season->id)->whereIn('status', ['reported', 'disputed'])->exists();
            $graceOver = $season->ends_at->copy()->addHours((int) $season->setting('dispute_grace_hours'))->lessThanOrEqualTo($now);
            if (! $open || $graceOver) {
                $this->finish($season);
            }
        }

    }

    /** Fecha a temporada: cancela o que nao foi concluido e grava snapshot imutavel. */
    public function finish(RankedSeason $season): void
    {
        $finished = DB::transaction(function () use ($season) {
            $season = RankedSeason::query()->with('formats')->lockForUpdate()->findOrFail($season->id);
            if ($season->status !== 'closing') {
                return null;
            }
            RankedMatch::query()->where('season_id', $season->id)->whereIn('status', ['pending', 'reported', 'disputed'])->update(['status' => 'cancelled']);
            foreach ($season->formats as $format) {
                foreach ($this->leaderboard->rows($season, $format->format, null, true) as $row) {
                    $season->snapshots()->updateOrCreate(
                        ['format' => $format->format, 'player_profile_id' => $row['rating']->player_profile_id],
                        ['final_rating' => $row['rating']->rating, 'peak_rating' => $row['rating']->peak_rating, 'rank' => $row['rank'], 'tier' => $row['tier']['division'],
                            'stats' => $row['rating']->only(['played', 'wins', 'losses', 'draws'])],
                    );
                }
            }
            $season->update(['status' => 'finished', 'finished_at' => now()]);

            return $season;
        });
        if ($finished) {
            $this->notifier->seasonFinished($finished);
        }
    }

    /** Temporada ativa para o formato: a do clube (se pedida) ou a global. */
    public function activeFor(string $format, ?int $clubId = null): ?RankedSeason
    {
        $this->refresh();

        return RankedSeason::query()->with('formats')->where('status', 'active')
            ->when($clubId, fn ($q) => $q->where('scope', 'club')->where('club_id', $clubId), fn ($q) => $q->where('scope', 'global'))
            ->whereHas('formats', fn ($q) => $q->where('format', $format)->where('enabled', true))
            ->orderBy('starts_at')->first();
    }

    /** @return Collection<int,RankedSeason> */
    public function visibleSeasons(?User $user): Collection
    {
        $this->refresh();
        $clubIds = $user ? Club::whereHas('members', fn ($q) => $q->where('users.id', $user->id))->pluck('id') : collect();

        return RankedSeason::query()->with(['formats', 'club'])->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->where('scope', 'global')->orWhereIn('club_id', $clubIds))
            ->orderByRaw("case status when 'active' then 0 when 'closing' then 1 when 'scheduled' then 2 else 3 end")
            ->orderByDesc('starts_at')->get();
    }

    private function fail(bool $condition, string $field, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
