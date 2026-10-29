<?php

namespace Tests\Feature;

use App\Http\Middleware\AsyncFormRedirects;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Formularios enviados por fetch (public/js/async-forms.js) sem recarregar a pagina. */
class AsyncFormsTest extends TestCase
{
    use RefreshDatabase;

    private const ASYNC = [AsyncFormRedirects::HEADER => '1', 'Accept' => 'text/html, application/json;q=0.9'];

    public function test_async_match_result_returns_the_same_page_as_redirect_and_keeps_the_flash(): void
    {
        [$organizer, $tournament, $match] = $this->startedTournament();
        $page = route('tournaments.show', $tournament);

        $response = $this->actingAs($organizer)
            ->withHeaders(self::ASYNC + ['Referer' => $page])
            ->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 2, 'player_two_games' => 1]);

        $response->assertOk()->assertExactJson(['redirect' => $page]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        // A mensagem continua na sessao para a pagina que o script busca em seguida.
        $response->assertSessionHas('status', 'Resultado salvo.');
        $this->assertSame(GameMatch::STATUS_FINISHED, $match->fresh()->status);

        $this->actingAs($organizer)->withHeaders(self::ASYNC)->get($page)
            ->assertOk()->assertSee('Resultado salvo.')->assertSee('2 × 1');
    }

    public function test_normal_form_post_still_redirects(): void
    {
        [$organizer, $tournament, $match] = $this->startedTournament();

        $this->actingAs($organizer)
            ->from(route('tournaments.show', $tournament))
            ->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 2, 'player_two_games' => 0])
            ->assertRedirect(route('tournaments.show', $tournament));
    }

    public function test_async_validation_error_goes_back_with_errors_instead_of_json_422(): void
    {
        [$organizer, $tournament, $match] = $this->startedTournament();
        $page = route('tournaments.show', $tournament);

        $this->actingAs($organizer)
            ->withHeaders(self::ASYNC + ['Referer' => $page])
            ->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 'x', 'player_two_games' => 0])
            ->assertOk()->assertExactJson(['redirect' => $page])
            ->assertSessionHasErrors('player_one_games');

        $this->assertSame(GameMatch::STATUS_PENDING, $match->fresh()->status);
    }

    public function test_async_post_that_leads_to_another_page_returns_that_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withHeaders(self::ASYNC)->post(route('tournaments.store'), [
            'name' => 'Liga de sexta', 'format' => 'Pauper', 'tournament_date' => '2026-11-06',
            'system' => 'round_robin',
        ]);

        $tournament = Tournament::where('name', 'Liga de sexta')->sole();
        $response->assertOk()->assertJsonPath('redirect', route('tournaments.show', $tournament));
    }

    public function test_guest_async_post_is_sent_to_login(): void
    {
        [, $tournament, $match] = $this->startedTournament();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(self::ASYNC)
            ->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 2, 'player_two_games' => 0])
            ->assertOk()->assertJsonStructure(['redirect']);

        // O script navega para fora (pagina de entrada) e o resultado nao e gravado.
        $this->assertSame(GameMatch::STATUS_PENDING, $match->fresh()->status);
    }

    public function test_pages_load_the_async_form_script_and_keep_the_release_notice_out_of_updates(): void
    {
        [$organizer, $tournament] = $this->startedTournament();

        $this->actingAs($organizer)->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('js/vendor/idiomorph.min.js', false)
            ->assertSee('js/async-forms.js', false)
            ->assertSee('id="release-notice" class="alert alert-success" role="status" hidden data-async-keep', false)
            // Cartao de partida: ids estaveis para a atualizacao no lugar e sem diretiva Blade vazando no HTML.
            ->assertSee('id="match-'.$tournament->matches()->first()->id.'"', false)
            ->assertSee('id="round-1"', false)
            ->assertDontSee('@else', false)
            ->assertDontSee('Resultado travado');
    }

    /** @return array{0: User, 1: Tournament, 2: GameMatch} */
    private function startedTournament(): array
    {
        $organizer = User::factory()->create();
        $tournament = $organizer->tournaments()->create([
            'name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
        foreach (['Ajani', 'Chandra', 'Jace', 'Liliana'] as $name) {
            $tournament->players()->create(['name' => $name]);
        }
        $this->actingAs($organizer)->post(route('tournaments.start', $tournament));
        $match = $tournament->matches()->where('status', GameMatch::STATUS_PENDING)->firstOrFail();

        return [$organizer, $tournament, $match];
    }
}
