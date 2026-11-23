<?php

namespace App\Services;

use App\Exceptions\AvatarException;
use App\Models\Player;
use App\Models\PlayerProfile;
use App\Models\PrizeAward;
use App\Models\Tournament;
use App\Models\TournamentPrize;
use App\Models\User;
use App\Support\OffensiveText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Premiação do torneio: o organizador define o que cada colocação ganha (boosters e um troféu ou medalha) e,
 * quando o torneio encerra, os prêmios vão sozinhos para quem ficou em cada lugar.
 * Booster é prêmio físico: fica "a receber" até o organizador entregar ou o jogador marcar que recebeu.
 * Troféu e medalha são colecionáveis: ficam no perfil e até 3 vão para o destaque.
 */
class PrizeService
{
    public function __construct(private readonly StandingsService $standings, private readonly AvatarService $avatars) {}

    /**
     * Grava a premiação inteira (o que não vier some). Só antes de o torneio encerrar.
     *
     * A arte do troféu é opcional: sem envio novo a atual continua, e remove_image tira. Toda arte vira um quadrado de
     * TournamentPrize::IMAGE_SIZE px (AvatarService::storeSquare), então o perfil mostra todas do mesmo jeito.
     *
     * @param  array<int|string, mixed>  $rows  por colocação: has_trophy, style, trophy_label, remove_image, has_boosters, boosters, booster_label
     * @param  array<int|string, UploadedFile>  $images  arte do troféu por colocação
     */
    public function save(Tournament $tournament, array $rows, array $images = []): void
    {
        $wanted = [];
        $drop = [];
        foreach (array_keys(TournamentPrize::PLACEMENTS) as $placement) {
            $row = is_array($rows[$placement] ?? null) ? $rows[$placement] : [];
            // O formulário manda uma chave por prêmio (has_trophy, has_boosters). Desligada, os campos dele não contam,
            // mesmo preenchidos; ligada, o prêmio precisa estar completo. Sem a chave, vale o que veio nos campos.
            $trophyOff = array_key_exists('has_trophy', $row) && empty($row['has_trophy']);
            $boostersOff = array_key_exists('has_boosters', $row) && empty($row['has_boosters']);
            $boosters = $boostersOff ? 0 : ($row['boosters'] ?? 0);
            $style = ! $trophyOff && is_string($row['style'] ?? null) ? $row['style'] : '';
            if ($trophyOff) {
                unset($images[$placement]);
            }
            $this->require(empty($row['has_trophy']) || $style !== '', "prizes.{$placement}.style", 'Escolha a peça: troféu, medalha, coroa ou escudo.');
            $this->require(empty($row['has_boosters']) || (is_numeric($boosters) && $boosters >= 1), "prizes.{$placement}.boosters", 'Informe quantos boosters (de 1 a '.TournamentPrize::MAX_BOOSTERS.') ou desligue os boosters.');

            $this->require(
                $boosters === null || $boosters === '' || (is_numeric($boosters) && (int) $boosters == $boosters && $boosters >= 0 && $boosters <= TournamentPrize::MAX_BOOSTERS),
                "prizes.{$placement}.boosters", 'Informe de 0 a '.TournamentPrize::MAX_BOOSTERS.' boosters.'
            );
            $this->require($style === '' || isset(TournamentPrize::STYLES[$style]), "prizes.{$placement}.style", 'Escolha uma peça da lista.');
            $boosterLabel = $this->label($row['booster_label'] ?? null, "prizes.{$placement}.booster_label");
            $trophyLabel = $this->label($row['trophy_label'] ?? null, "prizes.{$placement}.trophy_label");

            if ((int) $boosters > 0) {
                $wanted[$placement.':'.TournamentPrize::KIND_BOOSTER] = ['quantity' => (int) $boosters, 'style' => null, 'label' => $boosterLabel];
            }
            $this->require(! isset($images[$placement]) || $style !== '', "images.{$placement}", 'Escolha a peça (troféu, medalha, coroa ou escudo) para usar uma imagem.');
            if ($style !== '') {
                $wanted[$placement.':'.TournamentPrize::KIND_TROPHY] = ['quantity' => 1, 'style' => $style, 'label' => $trophyLabel];
                if (! empty($row['remove_image']) && ! isset($images[$placement])) {
                    $drop[$placement] = true;
                }
            }
        }
        $this->require($tournament->status !== Tournament::STATUS_FINISHED, 'prizes', 'O '.$tournament->kindNoun().' já encerrou e os prêmios foram entregues. A premiação não pode mais mudar.');

        // As artes novas são gravadas antes da transação; se algo falhar depois, saem do storage.
        $stored = [];
        try {
            foreach ($images as $placement => $file) {
                $stored[$placement] = $this->avatars->storeSquare($file, TournamentPrize::IMAGE_SIZE);
            }
        } catch (AvatarException $e) {
            $this->forgetImages($stored);
            throw ValidationException::withMessages(["images.{$placement}" => $e->getMessage()]);
        }

        try {
            $replaced = DB::transaction(function () use ($tournament, $wanted, $stored, $drop): array {
                $t = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
                $this->require($t->status !== Tournament::STATUS_FINISHED, 'prizes', 'O '.$t->kindNoun().' já encerrou e os prêmios foram entregues. A premiação não pode mais mudar.');
                $replaced = [];

                foreach ($t->prizes()->get() as $prize) {
                    $key = $prize->placement.':'.$prize->kind;
                    if (! isset($wanted[$key])) {
                        $replaced[] = $prize->image_path;
                        $prize->delete();

                        continue;
                    }
                    $values = $wanted[$key];
                    if ($prize->isTrophy() && (isset($stored[$prize->placement]) || isset($drop[$prize->placement]))) {
                        $replaced[] = $prize->image_path;
                        $values['image_path'] = $stored[$prize->placement] ?? null;
                    }
                    $prize->update($values);
                    unset($wanted[$key]);
                }
                foreach ($wanted as $key => $values) {
                    [$placement, $kind] = explode(':', $key);
                    $image = $kind === TournamentPrize::KIND_TROPHY ? ($stored[(int) $placement] ?? null) : null;
                    $t->prizes()->create(['placement' => (int) $placement, 'kind' => $kind, 'image_path' => $image, ...$values]);
                }

                return $replaced;
            });
        } catch (\Throwable $e) {
            $this->forgetImages($stored);
            throw $e;
        }
        $this->forgetImages($replaced);
    }

    /**
     * Apaga do storage as artes que nenhum prêmio usa mais. Um torneio repetido aponta para a mesma arte do original,
     * então o arquivo só sai quando a última referência some.
     *
     * @param  array<int|string, ?string>  $paths
     */
    public function forgetImages(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if (! TournamentPrize::query()->where('image_path', $path)->exists()) {
                $this->avatars->delete($path);
            }
        }
    }

    /**
     * Entrega os prêmios de um torneio encerrado conforme a classificação final. Pode rodar de novo:
     * não duplica, e se uma correção de placar mudou o pódio o prêmio muda de mãos.
     * Chamado dentro da transação que encerra o torneio (ou corrige a final).
     */
    public function sync(Tournament $tournament): void
    {
        if ($tournament->status !== Tournament::STATUS_FINISHED) {
            return;
        }
        $prizes = $tournament->prizes()->with('awards')->get();
        if ($prizes->isEmpty()) {
            return;
        }

        // Sem as relações em memória: a classificação precisa ler o placar que acabou de ser gravado.
        $fresh = $tournament->withoutRelations();
        $ranking = $this->standings->withPodium($fresh, $this->standings->for($fresh));
        $players = $ranking->pluck('player');
        $podium = $ranking->filter(fn (array $row) => ! empty($row['podium']))->mapWithKeys(fn (array $row) => [$row['podium'] => $row['player']]);

        foreach ($prizes as $prize) {
            /** @var Collection<int, Player> $winners */
            $winners = $prize->placement === TournamentPrize::PARTICIPATION ? $players : collect([$podium->get($prize->placement)])->filter();
            $prize->awards->whereNotIn('player_id', $winners->pluck('id'))->each->delete();
            foreach ($winners as $player) {
                $prize->awards()->firstOrCreate(['player_id' => $player->id], ['player_profile_id' => $player->player_profile_id]);
            }
        }
    }

    /**
     * Premiação para as páginas do torneio, na ordem 1º, 2º, 3º, participação. Com o torneio encerrado, cada prêmio traz quem ganhou.
     *
     * @return Collection<int, array{placement: int, label: string, tone: string, prizes: Collection<int, TournamentPrize>}>
     */
    public function forTournament(Tournament $tournament): Collection
    {
        $prizes = $tournament->prizes()->with(['awards.player.profile'])->get()->groupBy('placement');

        return collect(array_keys(TournamentPrize::PLACEMENTS))->filter(fn (int $placement) => $prizes->has($placement))
            ->map(fn (int $placement) => [
                'placement' => $placement,
                'label' => TournamentPrize::PLACEMENTS[$placement],
                'tone' => $prizes[$placement]->first()->tone(),
                // Colecionável primeiro, boosters depois.
                'prizes' => $prizes[$placement]->sortByDesc(fn (TournamentPrize $prize) => $prize->isTrophy())->values(),
            ])->values();
    }

    /**
     * Resumo em texto para o convite: uma linha por colocação ("1º lugar: Troféu de campeão e 3 boosters").
     *
     * @return list<string>
     */
    public function summary(Tournament $tournament): array
    {
        return $this->forTournament($tournament)
            ->map(fn (array $place) => $place['label'].': '.$place['prizes']->map(fn (TournamentPrize $prize) => $prize->title())->join(', ', ' e '))
            ->all();
    }

    /**
     * Boosters do jogador, do mais recente ao mais antigo. Só o dono vê (e o organizador, na página do torneio).
     *
     * @return array{pending: Collection<int, PrizeAward>, redeemed: Collection<int, PrizeAward>}
     */
    public function inventory(PlayerProfile $profile): array
    {
        $awards = $this->awardsOf($profile, TournamentPrize::KIND_BOOSTER)->get();

        return [
            'pending' => $awards->whereNull('redeemed_at')->values(),
            'redeemed' => $awards->whereNotNull('redeemed_at')->sortByDesc('redeemed_at')->values(),
        ];
    }

    public function pendingCount(PlayerProfile $profile): int
    {
        return $this->awardsOf($profile, TournamentPrize::KIND_BOOSTER)->whereNull('redeemed_at')->count();
    }

    /**
     * Troféus e medalhas do perfil. Aparecem para quem pode ver o perfil, como as conquistas.
     *
     * @return array{featured: Collection<int, PrizeAward>, others: Collection<int, PrizeAward>, total: int}
     */
    public function trophies(PlayerProfile $profile): array
    {
        $awards = $this->awardsOf($profile, TournamentPrize::KIND_TROPHY)->get();

        return [
            'featured' => $awards->whereNotNull('showcase_position')->sortBy('showcase_position')->values(),
            'others' => $awards->whereNull('showcase_position')->values(),
            'total' => $awards->count(),
        ];
    }

    /** Marca o booster como recebido (pelo jogador ou pelo organizador). Repetir não muda a data. */
    public function redeem(PrizeAward $award, User $by): void
    {
        $this->require(! $award->prize->isTrophy(), 'prize', 'Só boosters são marcados como recebidos.');
        if (! $award->isRedeemed()) {
            $award->forceFill(['redeemed_at' => now(), 'redeemed_by' => $by->id])->save();
        }
    }

    /** O organizador desfaz uma entrega marcada por engano. */
    public function unredeem(PrizeAward $award): void
    {
        $award->forceFill(['redeemed_at' => null, 'redeemed_by' => null])->save();
    }

    /**
     * Põe o troféu no destaque do perfil ou tira de lá. Cabem PrizeAward::SHOWCASE_SLOTS.
     *
     * @return bool true se ficou em destaque
     */
    public function toggleShowcase(PrizeAward $award): bool
    {
        $this->require($award->prize->isTrophy(), 'prize', 'Só troféus e medalhas vão para o destaque.');

        return DB::transaction(function () use ($award): bool {
            if ($award->showcase_position !== null) {
                $award->forceFill(['showcase_position' => null])->save();

                return false;
            }
            $taken = PrizeAward::query()->where('player_profile_id', $award->player_profile_id)->whereNotNull('showcase_position')
                ->lockForUpdate()->pluck('showcase_position')->all();
            $free = array_values(array_diff(range(1, PrizeAward::SHOWCASE_SLOTS), $taken));
            $this->require($free !== [], 'prize', 'O destaque já tem '.PrizeAward::SHOWCASE_SLOTS.' peças. Tire uma para colocar outra.');
            $award->forceFill(['showcase_position' => $free[0]])->save();

            return true;
        });
    }

    /**
     * O que cada conta ganhou no torneio, para o aviso no sino.
     *
     * @return Collection<int, list<string>> títulos dos prêmios por id de usuário
     */
    public function wonByAccount(Tournament $tournament): Collection
    {
        return PrizeAward::query()->with(['prize', 'profile'])
            ->whereHas('prize', fn (Builder $query) => $query->where('tournament_id', $tournament->id))->get()
            ->filter(fn (PrizeAward $award) => $award->profile?->account_user_id)
            // Troféu primeiro; depois pela colocação, com a participação por último.
            ->sortBy(fn (PrizeAward $award) => [(int) ! $award->prize->isTrophy(), $award->prize->placement ?: 99])
            ->groupBy(fn (PrizeAward $award) => $award->profile->account_user_id)
            ->map(fn (Collection $awards) => $awards->map(fn (PrizeAward $award) => $award->prize->title())->values()->all());
    }

    private function awardsOf(PlayerProfile $profile, string $kind): Builder
    {
        return PrizeAward::query()->where('player_profile_id', $profile->id)
            ->whereHas('prize', fn (Builder $query) => $query->where('kind', $kind))
            ->with(['prize.tournament' => fn ($query) => $query->withCount('players')])
            ->latest('id');
    }

    /** Nome livre: sem espaços sobrando, no limite de tamanho e sem palavrão (aparece no perfil dos outros). */
    private function label(mixed $value, string $field): ?string
    {
        $label = trim(preg_replace('/\s+/u', ' ', is_string($value) ? $value : '') ?? '');
        if ($label === '') {
            return null;
        }
        $this->require(mb_strlen($label) <= TournamentPrize::LABEL_MAX, $field, 'Use no máximo '.TournamentPrize::LABEL_MAX.' caracteres.');
        $this->require(! OffensiveText::contains($label), $field, 'Esse nome tem palavra ofensiva. Escolha outro.');

        return $label;
    }

    private function require(bool $ok, string $field, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
