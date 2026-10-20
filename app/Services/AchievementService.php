<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\PlayerProfile;
use App\Models\ProfileAchievement;
use App\Models\Tournament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catalogo de conquistas. Cada conquista tem uma meta numerica; o progresso e calculado a partir
 * das estatisticas do jogador e, ao atingir a meta, e gravado em player_profile_achievements
 * (desbloqueio permanente, com data).
 */
class AchievementService
{
    public const CATEGORIES = ['matches' => 'Partidas', 'tournaments' => 'Torneios', 'social' => 'Social', 'collection' => 'Coleção'];

    public function __construct(private readonly PlayerStatsService $stats) {}

    /** @return array<string, array{0:string,1:string,2:string,3:string,4:int,5:string}> [icone, nome, descricao, categoria, meta, metrica] */
    public static function definitions(): array
    {
        return [
            'secret_comeback' => ['↗', 'De virada', 'Vença uma série casual confirmada após perder o primeiro game, com ordem registrada.', 'matches', 1, 'casual_comeback'],
            'secret_tribal' => ['✦', 'Uma só tribo', 'Cadastre 20 criaturas do mesmo subtipo no deck principal (sem sideboard).', 'collection', 20, 'tribal_count'],
            // Partidas
            'first_win' => ['🗡️', 'First Blood', 'Vença sua primeira partida', 'matches', 1, 'wins'],
            'clean_sweep' => ['🧹', 'Clean Sweep', 'Vença uma partida por 2–0', 'matches', 1, 'clean_sweeps'],
            'on_fire' => ['🔥', 'On Fire', 'Vença 3 partidas seguidas', 'matches', 3, 'best_streak'],
            'unstoppable' => ['⚡', 'Unstoppable', 'Vença 6 partidas seguidas', 'matches', 6, 'best_streak'],
            'grinder' => ['🛡️', 'Grinder', 'Jogue 25 partidas', 'matches', 25, 'played'],
            'centurion' => ['💯', 'Centurião', 'Vença 50 partidas', 'matches', 50, 'wins'],
            'zero_two' => ['💀', '0–2 Enjoyer', 'Sobreviva a uma derrota por 0–2', 'matches', 1, 'zero_two'],
            'bye' => ['🎲', 'BYE Merchant', 'Ganhe uma rodada de BYE', 'matches', 1, 'bye'],
            // Torneios
            'debut' => ['🎟️', 'Estreia', 'Dispute seu primeiro torneio', 'tournaments', 1, 'tournaments_played'],
            'regular' => ['📅', 'Figurinha Carimbada', 'Dispute 10 torneios', 'tournaments', 10, 'tournaments_played'],
            'podium' => ['🥉', 'No Pódio', 'Termine um torneio entre os 3 primeiros', 'tournaments', 1, 'podiums'],
            'almost_there' => ['🥈', 'Almost There', 'Chegue à final de um torneio', 'tournaments', 1, 'finals_reached'],
            'champion' => ['👑', 'Champion', 'Vença um torneio', 'tournaments', 1, 'titles'],
            'dynasty' => ['🏰', 'Dinastia', 'Vença 3 torneios', 'tournaments', 3, 'titles'],
            'undefeated' => ['🐐', 'Undefeated', 'Termine um torneio sem nenhuma derrota', 'tournaments', 1, 'undefeated_runs'],
            'clutch' => ['🎯', 'Clutch', 'Vença uma final ou disputa de 3º lugar perdendo um game', 'tournaments', 1, 'comebacks'],
            'multiformat' => ['🧭', 'Multiformato', 'Jogue torneios em 3 formatos diferentes', 'tournaments', 3, 'formats_played'],
            // Social
            'first_friend' => ['🤝', 'Parceiro de Mesa', 'Faça seu primeiro amigo', 'social', 1, 'friends'],
            'playgroup' => ['🍕', 'Playgroup', 'Tenha 5 amigos', 'social', 5, 'friends'],
            'organizer' => ['📋', 'Organizador', 'Conclua um torneio como organizador', 'social', 1, 'organized_finished'],
            'host' => ['🎪', 'Anfitrião', 'Conclua 5 torneios como organizador', 'social', 5, 'organized_finished'],
            // Colecao
            'deckbuilder' => ['🃏', 'Deckbuilder', 'Monte um deck com pelo menos 60 cartas', 'collection', 60, 'max_deck_cards'],
            'arsenal' => ['🗃️', 'Arsenal', 'Tenha 5 decks cadastrados', 'collection', 5, 'decks'],
            'commander_ready' => ['🐉', 'Commander Ready', 'Monte um deck Commander completo: 100 cartas com comandante', 'collection', 1, 'commander_complete'],
            'rainbow' => ['🌈', 'Arco-íris', 'Monte um deck com as cinco cores', 'collection', 1, 'five_color'],
        ];
    }

    /** Metricas atuais do perfil. */
    public function metrics(PlayerProfile $profile, ?array $summary = null): array
    {
        $summary ??= $this->stats->for($profile);
        $facts = $summary['facts'];

        $deckIds = $profile->decks()->pluck('id');
        $cardCounts = DeckCard::query()->whereIn('deck_id', $deckIds)->whereIn('board', [DeckCard::BOARD_MAIN, DeckCard::BOARD_COMMANDER])
            ->groupBy('deck_id')->select('deck_id', DB::raw('SUM(quantity) as total'))->pluck('total', 'deck_id');

        $commanderComplete = Deck::query()->whereIn('id', $deckIds)->where('format', 'Commander')->get()
            ->contains(fn (Deck $deck) => (int) ($cardCounts[$deck->id] ?? 0) >= 100
                && $deck->cards()->where('board', DeckCard::BOARD_COMMANDER)->exists());

        $fiveColor = Deck::query()->whereIn('id', $deckIds)->get()
            ->contains(fn (Deck $deck) => $deck->computedColors() === 'WUBRG');

        // Only actual mainboard creature subtypes count; names and lands never do.
        $tribes = [];
        foreach (DeckCard::query()->whereIn('deck_id', $deckIds)->where('board', DeckCard::BOARD_MAIN)->get() as $card) {
            foreach (explode(' // ', $card->type_line ?? '') as $face) {
                if (! preg_match('/\bCreature\b[^—]*—\s*(.+)$/u', $face, $parts)) continue;
                foreach (array_unique(preg_split('/\s+/u', trim($parts[1]))) as $type) {
                    $tribes[$card->deck_id][$type] = ($tribes[$card->deck_id][$type] ?? 0) + $card->quantity;
                }
                break; // A double-faced card is still one physical card.
            }
        }
        $tribalCount = collect($tribes)->flatMap(fn ($types) => array_values($types))->max() ?? 0;
        $comeback = \App\Models\CasualMatch::query()->with('challenge')->where('status', 'confirmed')
            ->whereHas('challenge', fn ($q) => $q->where(fn ($q) => $q->where('challenger_id', $profile->id)->orWhere('opponent_id', $profile->id)))
            ->get()->contains(function ($match) use ($profile) {
                $side = $match->challenge->challenger_id === $profile->id ? 1 : 2;
                $winner = $match->challenger_games > $match->opponent_games ? 1 : 2;
                return $winner === $side && count($match->game_order ?? []) > 1 && $match->game_order[0] !== $side;
            });
        $account = $profile->account;

        return [
            'casual_comeback' => (int) $comeback,
            'tribal_count' => (int) $tribalCount,
            'wins' => $summary['wins'],
            'played' => $summary['played'],
            'best_streak' => $summary['best_streak'],
            'clean_sweeps' => $facts['clean_sweeps'],
            'zero_two' => (int) $facts['zero_two'],
            'bye' => (int) $facts['bye'],
            'tournaments_played' => $facts['tournaments_played'],
            'podiums' => $facts['podiums'],
            'finals_reached' => $facts['titles'] + $facts['runner_ups'],
            'titles' => $facts['titles'],
            'undefeated_runs' => $facts['undefeated_runs'],
            'comebacks' => $facts['comebacks'],
            'formats_played' => $facts['formats_played'],
            'friends' => $account ? $account->friendProfiles()->wherePivot('status', FriendshipService::ACCEPTED)->count() : 0,
            'organized_finished' => $account ? $account->tournaments()->where('status', Tournament::STATUS_FINISHED)->count() : 0,
            'max_deck_cards' => (int) ($cardCounts->max() ?? 0),
            'decks' => $deckIds->count(),
            'commander_complete' => (int) $commanderComplete,
            'five_color' => (int) $fiveColor,
        ];
    }

    /**
     * Grava as conquistas recem-atingidas. Desbloqueios sao permanentes.
     *
     * @return list<string> chaves desbloqueadas agora
     */
    public function sync(PlayerProfile $profile, ?array $summary = null): array
    {
        $metrics = $this->metrics($profile, $summary);
        $owned = $profile->achievements()->pluck('achievement')->all();
        $new = [];

        foreach (self::definitions() as $key => [, , , , $target, $metric]) {
            if (! in_array($key, $owned, true) && ($metrics[$metric] ?? 0) >= $target) {
                $record = $profile->achievements()->firstOrCreate(['achievement' => $key], ['unlocked_at' => now()]);
                if ($record->wasRecentlyCreated) $new[] = $key;
            }
        }

        return $new;
    }

    /** Catalogo completo com progresso, data de desbloqueio e raridade. */
    public function catalog(PlayerProfile $profile, ?array $summary = null): Collection
    {
        $metrics = $this->metrics($profile, $summary);
        $owned = $profile->achievements()->get()->keyBy('achievement');

        $players = max(1, PlayerProfile::query()->whereNotNull('account_user_id')->count());
        $holders = ProfileAchievement::query()->groupBy('achievement')
            ->select('achievement', DB::raw('COUNT(*) as total'))->pluck('total', 'achievement');

        return collect(self::definitions())->map(function (array $definition, string $key) use ($metrics, $owned, $players, $holders): array {
            [$icon, $name, $description, $category, $target, $metric] = $definition;
            $record = $owned->get($key);
            $secret = str_starts_with($key, 'secret_');
            $current = min($target, (int) ($metrics[$metric] ?? 0));
            if ($secret && ! $record) {
                $icon = '🔒'; $name = 'Conquista secreta'; $description = 'Continue jogando e explorando para descobrir.';
                $current = 0; $target = 1;
            }

            return [
                'key' => $key, 'icon' => $icon, 'name' => $name, 'description' => $description,
                'category' => $category, 'target' => $target, 'current' => $record ? $target : $current,
                'percent' => $record ? 100 : (int) floor($current / $target * 100),
                'unlocked' => (bool) $record, 'secret' => $secret,
                'unlocked_at' => $record?->unlocked_at,
                'is_new' => $record && $record->seen_at === null,
                'rarity' => min(100, (int) round(((int) ($holders[$key] ?? 0)) / $players * 100)),
            ];
        })->values();
    }

    public function markSeen(PlayerProfile $profile): void
    {
        $profile->achievements()->whereNull('seen_at')->update(['seen_at' => now()]);
    }
}
