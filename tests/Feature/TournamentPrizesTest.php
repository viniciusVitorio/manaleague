<?php

namespace Tests\Feature;

use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PrizeAward;
use App\Models\Tournament;
use App\Models\TournamentPrize;
use App\Models\User;
use App\Services\PrizeService;
use App\Services\StandingsService;
use App\Support\OffensiveText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Premiação do torneio: o organizador define por colocação, o encerramento entrega, booster é marcado como recebido e troféu vai para o perfil. */
class TournamentPrizesTest extends TestCase
{
    use RefreshDatabase;

    private const PRIZES = [
        1 => ['boosters' => 3, 'booster_label' => 'Bloomburrow', 'style' => 'trophy', 'trophy_label' => 'Taça da Loja'],
        2 => ['boosters' => 1, 'style' => 'medal'],
        3 => ['boosters' => '', 'style' => ''],
        0 => ['boosters' => 1, 'style' => ''],
    ];

    public function test_organizer_defines_prizes_and_everyone_sees_what_is_at_stake(): void
    {
        [$t, $owner] = $this->arena();

        // Sem premiação: o organizador vê o convite para definir; a página pública não mostra a seção.
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()
            ->assertSee('Definir premiação')->assertSee(route('tournaments.prizes.edit', $t), false);
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertDontSee('id="premiacao"', false);

        $this->get(route('tournaments.prizes.edit', $t))->assertOk()
            ->assertSeeInOrder(['Premiação', '1º lugar', '2º lugar', '3º lugar', 'Participação', 'Salvar premiação']);
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => self::PRIZES])
            ->assertRedirect(route('tournaments.show', $t).'#premiacao');

        $this->assertDatabaseHas('tournament_prizes', ['tournament_id' => $t->id, 'placement' => 1, 'kind' => 'booster', 'quantity' => 3, 'label' => 'Bloomburrow']);
        $this->assertDatabaseHas('tournament_prizes', ['tournament_id' => $t->id, 'placement' => 1, 'kind' => 'trophy', 'style' => 'trophy', 'label' => 'Taça da Loja']);
        $this->assertDatabaseHas('tournament_prizes', ['tournament_id' => $t->id, 'placement' => 2, 'kind' => 'trophy', 'style' => 'medal', 'label' => null]);
        $this->assertSame(5, $t->prizes()->count());

        // Colecionável antes dos boosters, colocações em ordem, participação por último.
        $order = ['1º lugar', 'Taça da Loja', '3 boosters de Bloomburrow', '2º lugar', 'Medalha de 2º lugar', '1 booster', 'Participação', 'Todos os inscritos'];
        $this->get(route('tournaments.show', $t))->assertOk()->assertSeeInOrder($order)->assertSee('Editar premiação');
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertSeeInOrder($order)->assertDontSee('Editar premiação');

        // O convite resume a premiação para quem vai se inscrever.
        $guest = User::factory()->create();
        $this->actingAs($guest)->get(route('tournaments.invite.show', $t->invite_token))->assertOk()
            ->assertSee('1º lugar: Taça da Loja e 3 boosters de Bloomburrow');

        // Salvar de novo sem uma linha remove o que saiu.
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['boosters' => 2, 'style' => '']]])->assertRedirect();
        $this->assertSame(1, $t->prizes()->count());
        $this->assertDatabaseHas('tournament_prizes', ['tournament_id' => $t->id, 'placement' => 1, 'kind' => 'booster', 'quantity' => 2, 'label' => null]);
    }

    public function test_invalid_prizes_are_refused_and_only_the_organizer_edits(): void
    {
        [$t, $owner] = $this->arena();

        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['boosters' => 37]]])->assertSessionHasErrors('prizes.1.boosters');
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['style' => 'dragon']]])->assertSessionHasErrors('prizes.1.style');
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [2 => ['style' => 'medal', 'trophy_label' => Str::repeat('a', 61)]]])->assertSessionHasErrors('prizes.2.trophy_label');
        // Nome ofensivo não entra: ele apareceria no perfil de quem ganhar.
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['style' => 'trophy', 'trophy_label' => 'Taça dos M3rd4s']]])->assertSessionHasErrors('prizes.1.trophy_label');
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['style' => 'trophy', 'trophy_label' => 'Taça do Merda']]])->assertSessionHasErrors('prizes.1.trophy_label');
        $this->assertSame(0, $t->prizes()->count());

        // Jogador inscrito e estranho não mexem na premiação.
        $player = $t->players()->first()->profile->account;
        $this->actingAs($player)->get(route('tournaments.prizes.edit', $t))->assertForbidden();
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => self::PRIZES])->assertForbidden();
        $this->actingAs(User::factory()->create())->put(route('tournaments.prizes.update', $t), ['prizes' => self::PRIZES])->assertForbidden();
        $this->assertSame(0, $t->prizes()->count());
    }

    public function test_form_lists_each_placement_with_the_collectible_before_the_boosters_and_switches_decide_what_is_saved(): void
    {
        [$t, $owner] = $this->arena();

        // Mesma ordem do quadro do torneio: colocação, troféu ou medalha, boosters. Nada ligado num torneio sem premiação.
        $this->actingAs($owner)->get(route('tournaments.prizes.edit', $t))->assertOk()
            ->assertSeeInOrder(['1º lugar', 'Troféu ou medalha', 'Boosters', '2º lugar', 'Troféu ou medalha', 'Boosters', 'Participação', 'Troféu ou medalha', 'Boosters', 'Salvar premiação'])
            ->assertDontSee('value="1" checked', false);

        // Chave desligada: os campos preenchidos embaixo dela não viram prêmio (nem a imagem escolhida).
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [
            1 => ['has_trophy' => '1', 'style' => 'crown', 'trophy_label' => 'Coroa da Loja', 'has_boosters' => '0', 'boosters' => 3, 'booster_label' => 'Bloomburrow'],
            2 => ['has_trophy' => '0', 'style' => 'medal', 'trophy_label' => 'Não vale', 'has_boosters' => '1', 'boosters' => 2],
        ]])->assertRedirect();
        $this->assertSame(['1:trophy:Coroa da Loja', '2:booster:'], $t->prizes()->orderBy('placement')->get()->map(fn ($p) => "{$p->placement}:{$p->kind}:{$p->label}")->all());

        // O que está salvo volta com a chave ligada e os campos preenchidos.
        $this->get(route('tournaments.prizes.edit', $t))->assertOk()
            ->assertSee('name="prizes[1][has_trophy]" value="1" checked', false)
            ->assertSee('name="prizes[2][has_boosters]" value="1" checked', false)
            ->assertDontSee('name="prizes[1][has_boosters]" value="1" checked', false)
            ->assertSee('value="Coroa da Loja"', false);

        // Chave ligada sem quantidade: erro dizendo o que fazer, e nada muda.
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['has_boosters' => '1', 'boosters' => '']]])->assertSessionHasErrors('prizes.1.boosters');
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['has_boosters' => '1', 'boosters' => 0]]])->assertSessionHasErrors('prizes.1.boosters');
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['has_trophy' => '1', 'style' => '']]])->assertSessionHasErrors('prizes.1.style');
        $this->assertSame(2, $t->prizes()->count());
    }

    public function test_offensive_text_filter_catches_disguises_without_blocking_innocent_names(): void
    {
        foreach (['Copa do Caralho', 'f.d.p', 'p u t a', 'PORRA!', 'm3rd4'] as $bad) {
            $this->assertTrue(OffensiveText::contains($bad), $bad);
        }
        foreach (['Taça Liga de Verão', 'Mono Preto Open', 'Copa Pau-Brasil', 'Circuito 2026', 'Troféu Computação', null, ''] as $fine) {
            $this->assertFalse(OffensiveText::contains($fine), (string) $fine);
        }
    }

    public function test_finishing_the_tournament_awards_each_placement_once_and_notifies_winners(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => self::PRIZES]);
        $this->finish($t);

        [$first, $second] = $this->podium($t);
        $trophy = $t->prizes()->where(['placement' => 1, 'kind' => 'trophy'])->sole();
        $this->assertSame([$first->id], $trophy->awards()->pluck('player_id')->all());
        $this->assertSame([$first->id], $t->prizes()->where(['placement' => 1, 'kind' => 'booster'])->sole()->awards()->pluck('player_id')->all());
        $this->assertSame([$second->id], $t->prizes()->where(['placement' => 2, 'kind' => 'trophy'])->sole()->awards()->pluck('player_id')->all());
        // Participação: os quatro inscritos, inclusive o campeão.
        $this->assertSame(4, $t->prizes()->where('placement', TournamentPrize::PARTICIPATION)->sole()->awards()->count());
        $this->assertSame(8, PrizeAward::count());

        // Rodar de novo não duplica.
        app(PrizeService::class)->sync($t->fresh());
        $this->assertSame(8, PrizeAward::count());

        // Um aviso por pessoa, com a lista do que ganhou (troféu primeiro).
        $notice = CommunityNotification::where('user_id', $first->profile->account_user_id)->where('type', 'tournament.prizes')->sole();
        $this->assertSame('Você ganhou em Copa da Loja: Taça da Loja, 3 boosters de Bloomburrow e 1 booster', $notice->title);
        $this->assertSame('/me/prizes', $notice->url);

        // Encerrado: a premiação não muda mais.
        $this->get(route('tournaments.prizes.edit', $t))->assertRedirect(route('tournaments.show', $t).'#premiacao');
        $this->put(route('tournaments.prizes.update', $t), ['prizes' => []])->assertSessionHasErrors('prizes');
        $this->assertSame(5, $t->prizes()->count());
        $this->get(route('tournaments.show', $t))->assertOk()
            ->assertSeeInOrder(['Premiação', '1º lugar', $first->name, 'Taça da Loja', 'Marcar como entregue'], false)
            ->assertDontSee('Editar premiação');
    }

    public function test_correcting_the_final_moves_the_prize_to_the_new_champion(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['boosters' => 2, 'style' => 'trophy']]]);
        $this->finish($t);
        [$first, $second] = $this->podium($t);

        $final = $t->matches()->where('stage', 'final')->sole();
        $scores = $final->player_one_id === $second->id ? ['player_one_games' => 2, 'player_two_games' => 0] : ['player_one_games' => 0, 'player_two_games' => 2];
        $this->put(route('matches.update', [$t, $final]), $scores)->assertRedirect();

        $this->assertSame([$second->id, $second->id], PrizeAward::orderBy('id')->pluck('player_id')->all());
        $this->assertDatabaseMissing('prize_awards', ['player_id' => $first->id]);
        $this->assertTrue(CommunityNotification::where('user_id', $second->profile->account_user_id)->where('type', 'tournament.prizes')->exists());
    }

    public function test_swiss_tournament_awards_from_the_final_standings(): void
    {
        [$t, $owner] = $this->arena(['system' => 'swiss', 'round_limit' => 1]);
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['style' => 'crown'], 3 => ['style' => 'shield']]]);
        $this->post(route('tournaments.start', $t))->assertRedirect();
        foreach ($t->matches()->get() as $match) {
            $this->put(route('matches.update', [$t, $match]), ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
        }
        $this->post(route('tournaments.advance', $t), ['round' => 1])->assertRedirect();
        $this->assertSame(Tournament::STATUS_FINISHED, $t->fresh()->status);

        $ranking = app(StandingsService::class)->for($t->fresh());
        $this->assertSame([$ranking[0]['player']->id], $t->prizes()->where('placement', 1)->sole()->awards()->pluck('player_id')->all());
        $this->assertSame([$ranking[2]['player']->id], $t->prizes()->where('placement', 3)->sole()->awards()->pluck('player_id')->all());
    }

    public function test_boosters_stay_pending_until_the_player_or_the_organizer_marks_them(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['boosters' => 3, 'booster_label' => 'Bloomburrow'], 2 => ['boosters' => 1]]]);
        $this->finish($t);
        [$first, $second] = $this->podium($t);
        $champion = $first->profile->account;
        $award = PrizeAward::where('player_id', $first->id)->sole();
        $other = PrizeAward::where('player_id', $second->id)->sole();

        // O jogador vê o que tem a receber, no perfil e em Meus prêmios.
        $this->actingAs($champion)->get(route('profiles.index'))->assertOk()->assertSee('1 prêmio a receber');
        $this->get(route('prizes.index'))->assertOk()
            ->assertSeeInOrder(['Meus prêmios', 'A receber', '3 boosters de Bloomburrow', 'Copa da Loja · 1º lugar · 10/11/2026', 'Já recebi'])
            ->assertDontSee('Recebidos');
        // Na página pública ele vê o próprio estado; quem não ganhou não vê entrega nenhuma.
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertSee('A receber');
        $this->actingAs(User::factory()->create())->get(route('arena.public', $t->public_slug))->assertOk()
            ->assertSee('3 boosters de Bloomburrow')->assertDontSee('A receber')->assertDontSee('Marcar como entregue');

        // Prêmio de outra pessoa não existe para mim.
        $this->actingAs($champion)->patch(route('prizes.redeem', $other))->assertNotFound();
        $this->assertNull($other->fresh()->redeemed_at);

        $this->patch(route('prizes.redeem', $award))->assertRedirect();
        $this->assertNotNull($award->fresh()->redeemed_at);
        $this->assertSame($champion->id, $award->fresh()->redeemed_by);
        $this->get(route('prizes.index'))->assertOk()->assertSeeInOrder(['Recebidos', '3 boosters de Bloomburrow', 'Recebido em'])->assertDontSee('Já recebi');
        $this->get(route('profiles.index'))->assertOk()->assertDontSee('a receber');

        // O organizador marca a entrega do vice e pode desfazer; jogador não usa a rota do organizador.
        $this->patch(route('tournaments.prizes.deliver', [$t, $other]))->assertForbidden();
        $this->actingAs($owner)->patch(route('tournaments.prizes.deliver', [$t, $other]))->assertRedirect();
        $this->assertNotNull($other->fresh()->redeemed_at);
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee('Entregue')->assertSee('Desfazer');
        $this->patch(route('tournaments.prizes.deliver', [$t, $other]), ['undo' => 1])->assertRedirect();
        $this->assertNull($other->fresh()->redeemed_at);

        // Prêmio de outro torneio não passa pela rota deste.
        [$elsewhere] = $this->arena([], $owner);
        $this->patch(route('tournaments.prizes.deliver', [$elsewhere, $other]))->assertNotFound();
    }

    public function test_empty_inventory_says_what_to_do(): void
    {
        $this->actingAs(User::factory()->create())->get(route('prizes.index'))->assertOk()
            ->assertSee('Nenhum booster ainda.')->assertSee(route('tournaments.index'), false);
    }

    public function test_trophies_show_on_the_profile_for_owner_friend_and_stranger_but_boosters_do_not(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['boosters' => 3, 'style' => 'trophy', 'trophy_label' => 'Taça da Loja']]]);
        $this->finish($t);
        [$first, $second] = $this->podium($t);
        $champion = $first->profile->account;
        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $champion->friendProfiles()->attach($friend->playerProfile, ['status' => 'accepted']);
        $friend->friendProfiles()->attach($champion->playerProfile, ['status' => 'accepted']);

        $piece = ['Troféus e medalhas', 'Taça da Loja', 'Copa da Loja', '1º lugar · 4 jogadores · 10/11/2026'];
        $this->actingAs($champion)->get(route('profiles.index'))->assertOk()->assertSeeInOrder($piece)->assertSee('Destacar Taça da Loja');
        foreach ([$friend, User::factory()->create()] as $visitor) {
            $this->actingAs($visitor)->get(route('profiles.show', $first->profile))->assertOk()
                ->assertSeeInOrder($piece)
                ->assertDontSee('Destacar Taça da Loja')->assertDontSee('a receber')->assertDontSee('3 boosters');
        }
        // Quem não ganhou troféu não tem a seção.
        $this->actingAs($champion)->get(route('profiles.show', $second->profile))->assertOk()->assertDontSee('Troféus e medalhas');
    }

    public function test_owner_highlights_up_to_three_trophies(): void
    {
        $champion = null;
        $awards = collect();
        foreach (range(1, 4) as $i) {
            [$t, $owner] = $this->arena(['name' => "Copa {$i}"], null, $champion);
            $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [0 => ['style' => 'medal']]]);
            $this->finish($t);
            $champion ??= $t->players()->orderBy('id')->first()->profile->account;
            $awards->push(PrizeAward::where('player_profile_id', $champion->playerProfile->id)->latest('id')->first());
        }
        $this->assertSame(4, $awards->unique('id')->count());

        // Três entram no destaque, na ordem em que foram escolhidas; a quarta não cabe.
        foreach ($awards->take(3) as $award) {
            $this->actingAs($champion)->patch(route('prizes.showcase', $award))->assertRedirect(route('profiles.index').'#trofeus');
        }
        $this->assertSame([1, 2, 3], $awards->take(3)->map(fn ($a) => $a->fresh()->showcase_position)->all());
        $this->patch(route('prizes.showcase', $awards[3]))->assertSessionHasErrors('prize');
        $this->assertNull($awards[3]->fresh()->showcase_position);

        $this->get(route('profiles.index'))->assertOk()
            ->assertSee('prize-featured', false)->assertSee('Tirar Medalha de participação do destaque');

        // Tirar uma abre a vaga; peça de outra pessoa responde 404.
        $this->patch(route('prizes.showcase', $awards[0]))->assertRedirect();
        $this->patch(route('prizes.showcase', $awards[3]))->assertRedirect();
        $this->assertSame(1, $awards[3]->fresh()->showcase_position);
        $this->actingAs(User::factory()->create())->patch(route('prizes.showcase', $awards[1]))->assertNotFound();
        $this->assertSame(2, $awards[1]->fresh()->showcase_position);

        // Booster não vai para o destaque.
        $booster = $awards[0]->prize->tournament->prizes()->create(['placement' => 1, 'kind' => 'booster', 'quantity' => 1]);
        $mine = $booster->awards()->create(['player_id' => $awards[0]->player_id, 'player_profile_id' => $champion->playerProfile->id]);
        $this->actingAs($champion)->patch(route('prizes.showcase', $mine))->assertSessionHasErrors('prize');
    }

    public function test_repeating_a_tournament_copies_the_prizes_but_not_the_awards(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => self::PRIZES]);
        $this->finish($t);

        $this->post(route('tournaments.repeat', $t), ['name' => 'Copa da Loja #2', 'tournament_date' => now()->addWeek()->toDateString(), 'copy_players' => 1])->assertRedirect();
        $copy = Tournament::where('name', 'Copa da Loja #2')->sole();

        $this->assertSame(5, $copy->prizes()->count());
        $this->assertDatabaseHas('tournament_prizes', ['tournament_id' => $copy->id, 'placement' => 1, 'kind' => 'trophy', 'label' => 'Taça da Loja']);
        $this->assertSame(0, PrizeAward::whereIn('tournament_prize_id', $copy->prizes()->pluck('id'))->count());
    }

    public function test_deleting_the_tournament_removes_its_prizes(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => self::PRIZES]);
        $this->finish($t);

        $this->delete(route('tournaments.destroy', $t))->assertRedirect();
        $this->assertSame(0, TournamentPrize::count());
        $this->assertSame(0, PrizeAward::count());
    }

    /** Todos contra todos até o fim: liga, final e 3º lugar, sempre com vitória do jogador um. */
    private function finish(Tournament $t): void
    {
        $this->post(route('tournaments.start', $t))->assertRedirect();
        $t->matches()->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 1]);
        $this->post(route('tournaments.finals', $t))->assertRedirect();
        foreach ($t->matches()->whereIn('stage', ['final', 'third_place'])->get() as $match) {
            $this->put(route('matches.update', [$t, $match]), ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
        }
        $this->assertSame(Tournament::STATUS_FINISHED, $t->fresh()->status);
    }

    /** @return array{0: Player, 1: Player} campeão e vice pela final */
    private function podium(Tournament $t): array
    {
        $final = $t->matches()->where('stage', 'final')->with(['playerOne.profile.account', 'playerTwo.profile.account'])->sole();

        return $final->player_one_games > $final->player_two_games ? [$final->playerOne, $final->playerTwo] : [$final->playerTwo, $final->playerOne];
    }

    /** Torneio de 4 inscritos com conta e deck. $regular entra como primeiro inscrito (para ganhar em vários torneios). */
    private function arena(array $extra = [], ?User $owner = null, ?User $regular = null): array
    {
        $owner ??= User::factory()->create();
        $t = $owner->tournaments()->create([
            'name' => 'Copa da Loja', 'format' => 'Pauper', 'system' => 'round_robin', 'tournament_date' => '2026-11-10',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40), ...$extra,
        ]);
        foreach (range(1, 4) as $i) {
            $profile = ($i === 1 && $regular ? $regular : User::factory()->create())->ensurePlayerProfile();
            $deck = $profile->decks()->create(['name' => "Deck {$i}", 'format' => 'Pauper']);
            $t->players()->create(['name' => "Jogador {$i}", 'player_profile_id' => $profile->id, 'deck_id' => $deck->id]);
        }

        return [$t, $owner];
    }
}
