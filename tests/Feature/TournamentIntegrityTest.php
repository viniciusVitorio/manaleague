<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regras de integridade do fluxo do torneio (STRIDE: Tampering / logica de negocio).
 */
class TournamentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_league_results_are_locked_after_finals_are_generated(): void
    {
        [$organizer, $tournament] = $this->leagueWithAllResults();
        $this->actingAs($organizer)->post(route('tournaments.finals', $tournament))->assertRedirect();
        $this->assertSame(Tournament::STATUS_FINALS, $tournament->fresh()->status);

        $leagueMatch = $tournament->matches()->where('stage', GameMatch::STAGE_LEAGUE)->where('status', GameMatch::STATUS_FINISHED)->firstOrFail();
        $before = $leagueMatch->only(['player_one_games', 'player_two_games']);

        $this->actingAs($organizer)
            ->put(route('matches.update', [$tournament, $leagueMatch]), ['player_one_games' => 0, 'player_two_games' => 2])
            ->assertStatus(409);
        $this->assertSame($before, $leagueMatch->fresh()->only(['player_one_games', 'player_two_games']));

        // A tela do organizador nao oferece mais o formulario para partidas travadas.
        $this->actingAs($organizer)->get(route('tournaments.show', $tournament))->assertOk()->assertSee('Resultado travado');
    }

    public function test_finals_close_the_tournament_and_can_still_be_corrected(): void
    {
        [$organizer, $tournament] = $this->leagueWithAllResults();
        $this->actingAs($organizer)->post(route('tournaments.finals', $tournament));

        $final = $tournament->matches()->where('stage', GameMatch::STAGE_FINAL)->sole();
        $third = $tournament->matches()->where('stage', GameMatch::STAGE_THIRD_PLACE)->sole();
        $this->actingAs($organizer)->put(route('matches.update', [$tournament, $final]), ['player_one_games' => 2, 'player_two_games' => 1]);
        $this->actingAs($organizer)->put(route('matches.update', [$tournament, $third]), ['player_one_games' => 2, 'player_two_games' => 0]);
        $this->assertSame(Tournament::STATUS_FINISHED, $tournament->fresh()->status);

        $playedAt = $final->fresh()->played_at;
        $this->actingAs($organizer)
            ->put(route('matches.update', [$tournament, $final]), ['player_one_games' => 1, 'player_two_games' => 2])
            ->assertSessionHas('status', 'Resultado corrigido.');
        $final->refresh();
        $this->assertSame([1, 2], [$final->player_one_games, $final->player_two_games]);
        $this->assertEquals($playedAt, $final->played_at, 'Correcao nao deve alterar a ordem cronologica da partida.');
        $this->assertSame(Tournament::STATUS_FINISHED, $tournament->fresh()->status);
    }

    public function test_matches_cannot_be_scored_before_the_league_starts(): void
    {
        $organizer = User::factory()->create();
        $tournament = $this->tournament($organizer);
        $one = $tournament->players()->create(['name' => 'One']);
        $two = $tournament->players()->create(['name' => 'Two']);
        $match = $tournament->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $one->id, 'player_two_id' => $two->id]);

        $this->actingAs($organizer)
            ->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 2, 'player_two_games' => 0])
            ->assertStatus(409);
    }

    public function test_invite_without_deck_fields_uses_profile_defaults(): void
    {
        $tournament = $this->tournament(User::factory()->create());
        $player = User::factory()->create(['name' => 'Sem Deck']);
        $player->ensurePlayerProfile()->update(['preferred_deck_name' => 'Burn', 'preferred_deck_colors' => 'R']);

        $this->actingAs($player)->post(route('tournaments.invite.store', $tournament->invite_token))
            ->assertRedirect(route('arena.public', $tournament->public_slug));
        $this->assertDatabaseHas('players', ['tournament_id' => $tournament->id, 'name' => 'Sem Deck', 'deck_name' => 'Burn', 'deck_colors' => 'R']);
    }

    public function test_invite_with_duplicate_nickname_returns_friendly_error(): void
    {
        $tournament = $this->tournament(User::factory()->create());
        $first = User::factory()->create(['name' => 'Jace']);
        $second = User::factory()->create(['name' => 'Jace']);

        $this->actingAs($first)->post(route('tournaments.invite.store', $tournament->invite_token))->assertRedirect();
        $this->actingAs($second)->post(route('tournaments.invite.store', $tournament->invite_token))->assertSessionHasErrors('nickname');
        $this->assertSame(1, $tournament->players()->count());
    }

    public function test_invite_respects_player_limit(): void
    {
        $tournament = $this->tournament(User::factory()->create(), ['max_players' => 4]);
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $tournament->players()->create(['name' => $name]);
        }

        $this->actingAs(User::factory()->create())
            ->post(route('tournaments.invite.store', $tournament->invite_token))
            ->assertStatus(409);
        $this->assertSame(4, $tournament->players()->count());
    }

    public function test_update_without_max_players_field_is_accepted(): void
    {
        $organizer = User::factory()->create();
        $tournament = $this->tournament($organizer, ['max_players' => 8]);

        $this->actingAs($organizer)
            ->put(route('tournaments.update', $tournament), ['name' => 'Renomeado', 'format' => 'Modern', 'tournament_date' => '2026-11-01'])
            ->assertRedirect(route('tournaments.show', $tournament));
        $this->assertSame('Renomeado', $tournament->fresh()->name);
    }

    public function test_new_public_slug_has_a_long_random_suffix(): void
    {
        $organizer = User::factory()->create();
        $this->actingAs($organizer)->post(route('tournaments.store'), ['name' => 'Liga de Sexta', 'format' => 'Pauper', 'tournament_date' => '2026-10-10']);

        $this->assertMatchesRegularExpression('/^liga-de-sexta-[a-z0-9]{16}$/', Tournament::query()->sole()->public_slug);
    }

    /** @return array{0: User, 1: Tournament} */
    private function leagueWithAllResults(): array
    {
        $organizer = User::factory()->create();
        $tournament = $this->tournament($organizer);
        foreach (['Ajani', 'Chandra', 'Jace', 'Liliana'] as $name) {
            $tournament->players()->create(['name' => $name]);
        }
        $this->actingAs($organizer)->post(route('tournaments.start', $tournament));

        foreach ($tournament->matches()->where('status', GameMatch::STATUS_PENDING)->get() as $match) {
            $this->actingAs($organizer)->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 2, 'player_two_games' => 1]);
        }

        return [$organizer, $tournament->fresh()];
    }

    private function tournament(User $user, array $overrides = []): Tournament
    {
        return $user->tournaments()->create([
            'name' => 'Arena', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'arena-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
            ...$overrides,
        ]);
    }
}
