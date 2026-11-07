<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Community\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Página do torneio por fase: mostra o que importa agora e não repete a mesma informação em vários blocos. */
class TournamentPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_shows_entries_and_start_without_an_empty_standings_table(): void
    {
        [$t, $owner] = $this->arena();

        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()
            // Link e QR ficam na janela Compartilhar (menu Mais), não no card de jogadores.
            ->assertSeeInOrder(['data-dialog-open="tourney-share"', 'Compartilhar inscrições', 'Link de inscrição', 'Exibir QR em tela cheia', 'Gerar novo link', '</dialog>', 'Inscrições', 'Jogadores', 'Check-in 0 de 4', 'Começar torneio'], false)
            ->assertSee('Todos contra todos')
            ->assertDontSee('class="standings"', false)
            ->assertDontSee('Rodada atual')
            ->assertDontSee('Resultados enviados');
    }

    public function test_running_tournament_puts_the_current_round_first_and_keeps_the_others_closed(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->post(route('tournaments.start', $t))->assertRedirect();

        $this->get(route('tournaments.show', $t))->assertOk()
            ->assertSeeInOrder(['Rodada atual', 'Rodada 1', 'Iniciar cronômetro', 'Classificação', 'Outras rodadas', 'Rodada 2'])
            ->assertSee('id="round-1"', false)
            ->assertDontSee('Link de inscrição');

        // Rodada 1 com resultado: a atual passa a ser a 2 e a 1 vai para "Outras rodadas".
        $t->matches()->where('round', 1)->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 0]);
        $this->get(route('tournaments.show', $t))->assertOk()
            ->assertSeeInOrder(['Rodada atual', 'Rodada 2', 'Classificação', 'Outras rodadas', 'Rodada 1']);
    }

    public function test_finished_tournament_shows_the_champion_once_with_the_podium(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $t->matches()->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 1]);
        $this->post(route('tournaments.finals', $t))->assertRedirect();
        foreach ($t->matches()->whereIn('stage', ['final', 'third_place'])->get() as $match) {
            $this->put(route('matches.update', [$t, $match]), ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
        }
        $this->assertSame(Tournament::STATUS_FINISHED, $t->fresh()->status);

        $final = $t->matches()->where('stage', 'final')->sole();
        $page = $this->get(route('tournaments.show', $t))->assertOk()
            // Pódio no palco: na leitura 1º, 2º e 3º (o CSS põe o 1º no meio), cada um com foto e nome.
            ->assertSeeInOrder(['Retrospectiva do campeonato', $final->playerOne->name.'</a> é o campeão', 'podium-step is-1', 'podium-crown', 'podium-avatar', $final->playerOne->name, 'Campeão', 'podium-step is-2', $final->playerTwo->name, '2º lugar', 'podium-step is-3', '3º lugar', 'Classificação final', 'Rodadas', 'Repetir torneio'], false);
        $this->assertSame(1, substr_count($page->getContent(), 'é o campeão de'), 'campeão aparece uma vez só');
        $this->assertMatchesRegularExpression('/id="repetir"\s+open/', $page->getContent(), 'repetir já vem aberto no encerrado');
    }

    public function test_final_standings_put_the_podium_on_top_with_medal_stripes(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $t->matches()->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 1]);
        $this->post(route('tournaments.finals', $t))->assertRedirect();
        $final = $t->matches()->where('stage', 'final')->sole();
        $third = $t->matches()->where('stage', 'third_place')->sole();
        $this->get(route('tournaments.show', $t))->assertOk()->assertDontSee('is-podium', false); // em andamento: sem faixa

        // O 2º da liga ganha a final: vira campeão e sobe para o topo da classificação final, em dourado.
        $this->put(route('matches.update', [$t, $final]), ['player_one_games' => 0, 'player_two_games' => 2])->assertRedirect();
        $this->put(route('matches.update', [$t, $third]), ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
        $this->assertSame(Tournament::STATUS_FINISHED, $t->fresh()->status);

        foreach ([route('tournaments.show', $t), route('arena.public', $t->public_slug)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $standings = substr($html, strpos($html, 'class="standings"'));
            $this->assertMatchesRegularExpression('/is-podium-1.*?'.preg_quote($final->playerTwo->name, '/').'.*?Campeão.*?is-podium-2.*?'.preg_quote($final->playerOne->name, '/').'.*?Vice.*?is-podium-3.*?'.preg_quote($third->playerOne->name, '/').'.*?3º lugar/s', $standings);
            $this->assertStringContainsString('Pódio pela final e pela disputa de 3º lugar', $standings);
            $this->assertStringContainsString($final->playerTwo->name.'</a> é o campeão', $html); // card e tabela contam a mesma história
        }
    }

    public function test_club_member_who_does_not_organize_sees_no_organizer_tools(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $club = app(ClubService::class)->create($owner, ['name' => 'Eagle TJS', 'privacy' => 'private']);
        $club->members()->attach($member->id, ['role' => 'member']);
        [$t] = $this->arena($owner);
        $t->forceFill(['club_id' => $club->id])->save(); // club_id fica fora do $fillable

        $this->actingAs($member)->get(route('tournaments.show', $t))->assertOk()
            ->assertSee('Jogadores')
            ->assertDontSee('Link de inscrição')->assertDontSee('Compartilhar')->assertDontSee('Marcar presença')->assertDontSee('Começar torneio')
            ->assertDontSee('Excluir torneio')->assertDontSee('Repetir torneio');

        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $this->actingAs($member)->get(route('tournaments.show', $t))->assertOk()
            ->assertSee('Rodada atual')
            ->assertDontSee('Iniciar cronômetro')->assertDontSee('Resultados enviados')->assertDontSee('Gerar final');
    }

    public function test_running_round_cards_show_tables_progress_and_round_states(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->post(route('tournaments.start', $t))->assertRedirect();

        $this->get(route('tournaments.show', $t))->assertOk()
            ->assertSeeInOrder(['Rodada atual', 'Liga: <b>0 de 6</b> partidas com resultado', 'faltam 6 para liberar a final', 'Mesa 1', 'Em jogo', 'Salvar resultado', 'Mesa 2'], false)
            ->assertSeeInOrder(['Outras rodadas', 'Rodada 2', 'A jogar', 'Rodada 3', 'A jogar'])
            ->assertDontSee('Corrigir placar');

        // Rodada 1 encerrada: vira "Concluída" em Outras rodadas e o resultado lançado vai para "Corrigir placar".
        $t->matches()->where('round', 1)->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 0]);
        $this->get(route('tournaments.show', $t))->assertOk()
            ->assertSee('Liga: <b>2 de 6</b> partidas com resultado', false)
            ->assertSeeInOrder(['Outras rodadas', 'Rodada 1', 'Concluída', 'tmatch-row is-winner', 'Corrigir placar'], false);
    }

    public function test_public_page_is_read_only_and_does_not_repeat_the_standings(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->post(route('tournaments.start', $t));

        $page = $this->get(route('arena.public', $t->public_slug))->assertOk()
            ->assertSeeInOrder(['Placar ao vivo', 'Modo TV', 'Rodada atual', 'Mesa 1', 'Em jogo', 'Classificação', 'Outras rodadas'])
            ->assertDontSee('Salvar resultado')->assertDontSee('Corrigir placar')->assertDontSee('Partidas ao vivo')->assertDontSee('Deck não informado');
        $this->assertSame(1, substr_count($page->getContent(), 'class="standings"'), 'uma classificação só');
    }

    public function test_submissions_queue_puts_disputes_first_and_explains_when_empty(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->get(route('matches.submissions.queue', $t))->assertOk()
            ->assertSee('← Copa da Loja')->assertSee('Nenhum resultado enviado ainda');

        $this->post(route('tournaments.start', $t));
        [$first, $second] = $t->matches()->where('round', 1)->orderBy('id')->get()->all();
        $reporter = User::factory()->create();
        $old = MatchResultSubmission::create(['match_id' => $first->id, 'submitted_by' => $reporter->id, 'status' => 'confirmed', 'player_one_games' => 2, 'player_two_games' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $disputed = MatchResultSubmission::create(['match_id' => $second->id, 'submitted_by' => $reporter->id, 'status' => 'disputed', 'player_one_games' => 2, 'player_two_games' => 1, 'dispute_reason' => 'Foi 1 a 2', 'idempotency_key' => (string) Str::uuid()]);
        $old->forceFill(['id' => $disputed->id + 1])->save(); // o confirmado é o mais recente, mas o contestado vem antes

        $this->get(route('matches.submissions.queue', $t))->assertOk()
            ->assertSeeInOrder(['Contestado', 'Motivo da contestação:', 'Decidir o placar', 'Resolver e publicar', 'Confirmado'])
            ->assertSee('Enviado por '.$reporter->name);
    }

    /** @return array{0: Tournament, 1: User} */
    private function arena(?User $owner = null, array $extra = []): array
    {
        $owner ??= User::factory()->create();
        $t = $owner->tournaments()->create([
            'name' => 'Copa da Loja', 'format' => 'Pauper', 'system' => 'round_robin', 'tournament_date' => '2026-11-10',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40), ...$extra,
        ]);
        foreach (range(1, 4) as $i) {
            $profile = User::factory()->create()->ensurePlayerProfile();
            $deck = $profile->decks()->create(['name' => "Deck {$i}", 'format' => 'Pauper']);
            $t->players()->create(['name' => "Jogador {$i}", 'player_profile_id' => $profile->id, 'deck_id' => $deck->id]);
        }

        return [$t, $owner];
    }
}
