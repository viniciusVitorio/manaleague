<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\Decks\BanListService;
use App\Services\Decks\DeckVersionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Multiplayer elimination only. Legacy Commander pods keep their original rules. */
class PodEliminationService
{
    /** @return list<list<int>> Pod sizes per round; no byes or arbitrary cuts. */
    public function plan(int $players, int $qualifiers): array
    {
        if ($players < 3 || $players > 128 || ! in_array($qualifiers, [1, 2], true)) {
            throw new LogicException('Use 3 a 128 participantes e 1 ou 2 classificados por mesa.');
        }
        if ($players <= 4) {
            return [[$players]];
        }
        // Prefer fewer, larger pods, but backtrack when the next round is impossible.
        for ($pods = (int) ceil($players / 4); $pods <= intdiv($players, 3); $pods++) {
            $next = $pods * $qualifiers;
            if ($next >= $players) {
                continue;
            }
            try {
                $rest = $this->plan($next, $qualifiers);
                $fours = $players - 3 * $pods;

                return [array_merge(array_fill(0, $fours, 4), array_fill(0, $pods - $fours, 3)), ...$rest];
            } catch (LogicException) {
                // Try another exact 3/4-seat partition.
            }
        }
        throw new LogicException('Quantidade incompatível: não há progressão com mesas de 3–4 até uma final de 3–4. Ajuste os inscritos ou classificados por mesa.');
    }

    public function validateConfiguration(int $players, int $qualifiers, bool $consolation): array
    {
        $plan = $this->plan($players, $qualifiers);
        if ($consolation && ($players !== 8 || $qualifiers !== 2)) {
            throw new LogicException('A consolação está disponível somente com 8 jogadores e 2 classificados por mesa.');
        }

        return $plan;
    }

    public function start(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            $t = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            if ($t->system !== 'pod_elimination' || $t->format !== 'Commander' || $t->ranked) {
                throw new LogicException('Eliminação por Pods exige Commander e não pode ser ranqueada.');
            }
            if ($t->status !== 'setup' || $t->matches()->exists()) {
                throw new LogicException('Este torneio já foi iniciado.');
            }
            $players = $t->players()->lockForUpdate()->get();
            $plan = $this->validateConfiguration($players->count(), $t->pod_qualifiers, $t->pod_consolation);
            if ($t->max_players && $players->count() > $t->max_players) {
                throw new LogicException('O total de inscritos excede o limite.');
            }
            if ($players->contains(fn ($p) => ! $p->checked_in_at)) {
                throw new LogicException('Confirme o check-in de todos os inscritos ou remova os ausentes antes de começar.');
            }
            if ($t->playersWithoutDeck()->exists()) {
                throw new LogicException('Escolha um deck salvo para cada inscrito com conta antes de começar.');
            }
            $players->load('deck');
            if ($players->contains(fn ($p) => $p->deck && ! $t->acceptsDeck($p->deck))) {
                throw new LogicException('Escolha decks compatíveis com Commander.');
            }
            if ($t->blocksBannedCards() && app(BanListService::class)->blockedByDeck($players->pluck('deck_id'), $t->format)) {
                throw new LogicException('Remova as cartas banidas no Commander antes de começar.');
            }
            app(DeckVersionService::class)->snapshotForTournament($t);
            $this->generate($t, $players->pluck('id')->all(), 1, $plan[0]);
            $t->forceFill(['status' => count($plan) === 1 ? 'finals' : 'league', 'round_limit' => count($plan), 'round_ends_at' => null])->save();
        });
    }

    /** Called by the canonical writer while holding the tournament lock. */
    public function record(Tournament $t, GameMatch $m, array $scores): void
    {
        $data = Validator::make($scores, [
            'placements' => ['required', 'array'], 'placements.*' => ['required', 'integer', 'min:1', 'max:'.count($m->pod_player_ids)],
            'confirm' => ['sometimes', 'boolean'],
            'tiebreak' => ['nullable', 'array'], 'tiebreak.*' => ['nullable', 'integer', 'min:1', 'max:'.count($m->pod_player_ids)],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        // Validate the original keys: casting would accept aliases such as "01" or "1suffix"
        // while retaining those invalid keys in the persisted placement maps.
        foreach (['placements', 'tiebreak'] as $field) {
            foreach (array_keys($data[$field] ?? []) as $id) {
                if (! in_array($id, $m->pod_player_ids, true)) {
                    throw ValidationException::withMessages([$field => 'Use os identificadores originais dos participantes da mesa.']);
                }
            }
        }
        $places = array_map('intval', $data['placements']);
        $ids = array_map('intval', array_keys($places));
        sort($ids);
        $expected = $m->pod_player_ids;
        sort($expected);
        $ranks = array_values($places);
        sort($ranks);
        $validRanks = true;
        foreach (array_count_values($ranks) as $rank => $size) {
            if ($rank !== 1 + count(array_filter($ranks, fn ($r) => $r < $rank))) {
                $validRanks = false;
            }
        }
        if ($ids !== $expected || ! $validRanks) {
            throw ValidationException::withMessages(['placements' => 'Informe a posição de cada participante da mesa.']);
        }
        $confirm = (bool) ($data['confirm'] ?? false);
        $resolved = array_filter($data['tiebreak'] ?? [], fn ($v) => $v !== null && $v !== '');
        $effective = $places;
        if ($resolved) {
            $resolved = array_map('intval', $resolved);
            // Only the original tied slots may be reordered. Never promote an untied loser.
            $resolvedIds = array_map('intval', array_keys($resolved));
            sort($resolvedIds);
            if ($resolvedIds !== $expected) {
                throw ValidationException::withMessages(['tiebreak' => 'Informe o desempate para todos os participantes, preservando os grupos empatados.']);
            }
            foreach (array_unique($ranks) as $rank) {
                $group = array_keys(array_filter($places, fn ($r) => $r === $rank));
                $actual = array_map(fn ($id) => $resolved[$id], $group);
                sort($actual);
                if ($actual !== range($rank, $rank + count($group) - 1)) {
                    throw ValidationException::withMessages(['tiebreak' => 'O desempate só pode ordenar jogadores do mesmo grupo empatado.']);
                }
            }
            if (blank($data['resolution_note'] ?? null)) {
                throw ValidationException::withMessages(['resolution_note' => 'Explique o critério e a decisão do desempate.']);
            }
            $effective = $resolved;
        }
        if ($confirm) {
            $cutoff = $m->stage === 'league' ? $t->pod_qualifiers : ($m->stage === 'final' ? 1 : 0);
            $above = count(array_filter($effective, fn ($r) => $r <= $cutoff));
            if ($cutoff && $above !== $cutoff) {
                throw ValidationException::withMessages(['tiebreak' => 'Resolva explicitamente o empate na vaga de classificação ou no título antes de confirmar.']);
            }
        }
        $m->update(['pod_scores' => $places, 'status' => $confirm ? 'finished' : 'pending',
            'pod_tiebreak_scores' => $resolved ?: null, 'pod_resolution_note' => $resolved ? trim($data['resolution_note']) : null,
            'pod_confirmed_at' => $confirm ? now() : null, 'pod_confirmed_by' => $confirm ? auth()->id() : null,
            'played_at' => $confirm ? now() : null]);
        if ($confirm) {
            $this->progress($t, $m->round);
        }
    }

    public function progress(Tournament $tournament, int $round): void
    {
        DB::transaction(function () use ($tournament, $round) {
            $t = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            if ($t->system !== 'pod_elimination') {
                throw new LogicException('Sistema incompatível.');
            }
            if ($t->status === 'finished' || $t->matches()->where('round', '>', $round)->exists()) {
                return;
            }
            $matches = $t->matches()->where('round', $round)->get();
            if ($matches->isEmpty() || $matches->contains(fn ($m) => $m->status !== 'finished' || ! $m->pod_confirmed_at)) {
                return;
            }
            if ($matches->contains('stage', 'final')) {
                $t->forceFill(['status' => 'finished', 'round_ends_at' => null])->save();

                return;
            }
            $next = [];
            $eliminated = [];
            foreach ($matches as $m) {
                foreach ($m->pod_tiebreak_scores ?: $m->pod_scores as $id => $rank) {
                    if ($rank <= $t->pod_qualifiers) {
                        $next[] = (int) $id;
                    } else {
                        $eliminated[] = (int) $id;
                    }
                }
            }
            $sizes = $this->plan(count($next), $t->pod_qualifiers)[0];
            $this->generate($t, $next, $round + 1, $sizes);
            if ($t->pod_consolation) {
                $this->generate($t, $eliminated, $round + 1, [4], 'consolation');
            }
            $t->forceFill(['status' => count($sizes) === 1 ? 'finals' : 'league', 'round_ends_at' => null])->save();
        });
    }

    public function standings(Tournament $t): Collection
    {
        $rows = $t->players()->with(['profile', 'deck'])->get()->mapWithKeys(fn ($p) => [$p->id => [
            'player' => $p, 'rank' => 1, 'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0,
            'games_for' => 0, 'games_against' => 0, 'game_diff' => 0, 'points' => 0, 'streak' => 0,
            'pod_status' => 'active',
        ]])->all();
        $matches = $t->matches()->orderBy('round')->orderBy('id')->get();
        $advancingPerRound = $matches->where('stage', 'league')->groupBy('round')->map(fn ($pods) => $pods->count() * $t->pod_qualifiers);
        foreach ($matches as $m) {
            foreach ($m->pod_player_ids as $id) {
                $rows[$id]['pod_status'] = 'active';
            }
            if ($m->status !== 'finished') {
                continue;
            }
            $advanced = $advancingPerRound->get($m->round, 0);
            foreach ($m->pod_tiebreak_scores ?: $m->pod_scores as $id => $place) {
                $rows[$id]['played']++;
                $rows[$id][$place === 1 ? 'wins' : 'losses']++;
                $rows[$id]['rank'] = match ($m->stage) {
                    'final' => $place, 'consolation' => 4 + $place,
                    default => $place <= $t->pod_qualifiers ? 1 : $advanced + 1,
                };
                $rows[$id]['pod_status'] = $m->stage === 'final' ? ($place === 1 ? 'champion' : 'finalist')
                    : ($m->stage === 'consolation' || $place > $t->pod_qualifiers ? 'eliminated' : 'qualified');
            }
        }
        $rankCounts = array_count_values(array_column(array_values($rows), 'rank'));

        return collect(array_values($rows))->sortBy('rank')->values()->map(function ($r) use ($t, $rankCounts) {
            if ($t->status === 'finished' && $r['rank'] <= 3 && $rankCounts[$r['rank']] === 1) {
                $r['podium'] = $r['rank'];
            }

            return $r;
        });
    }

    private function generate(Tournament $t, array $ids, int $round, array $sizes, string $stage = ''): void
    {
        if ($t->pod_random_draw) {
            shuffle($ids);
        }
        $stage = $stage ?: (count($sizes) === 1 ? 'final' : 'league');
        $number = (int) $t->matches()->where('round', $round)->max('pod_number');
        foreach ($sizes as $size) {
            $pod = array_splice($ids, 0, $size);
            $match = $t->matches()->create(['round' => $round, 'stage' => $stage, 'bracket' => 'elimination_pod',
                'pod_number' => ++$number, 'pod_player_ids' => $pod, 'player_one_id' => $pod[0], 'player_two_id' => $pod[1], 'status' => 'pending']);
            foreach ($pod as $id) {
                DB::table('elimination_pod_seats')->insert(['tournament_id' => $t->id, 'match_id' => $match->id, 'round' => $round, 'player_id' => $id]);
            }
        }
    }
}
