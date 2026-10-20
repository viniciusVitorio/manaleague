<?php

namespace Tests\Feature;

use App\Models\CommunityActivity;
use App\Models\CommunityNotification;
use App\Models\NotificationPreference;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Cross-module contracts: domain events feed notifications, the activity feed and the dashboard. */
class ModuleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function arena(string $system = 'round_robin', int $accounts = 4, array $extra = []): array
    {
        $owner = User::factory()->create();
        $t = $owner->tournaments()->create([
            'name' => 'Copa Integrada', 'format' => $system === 'commander_pods' ? 'Commander' : 'Pauper', 'system' => $system,
            'tournament_date' => '2026-10-20', 'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::random(32),
            'invite_token' => Str::random(40), ...$extra,
        ]);
        $users = [];
        foreach (range(1, $accounts) as $i) {
            $users[$i] = User::factory()->create();
            $t->players()->create(['name' => "Jogador {$i}", 'player_profile_id' => $users[$i]->ensurePlayerProfile()->id]);
        }
        return [$t, $owner, $users];
    }

    public function test_starting_a_tournament_notifies_every_linked_player_once(): void
    {
        [$t, $owner, $users] = $this->arena('swiss');
        $this->actingAs($owner)->post(route('tournaments.start', $t))->assertRedirect();
        foreach ($users as $user) {
            $this->assertSame(1, CommunityNotification::where('user_id', $user->id)->where('type', 'tournament.round')->count());
        }
        $this->assertSame(1, CommunityActivity::where('type', 'tournament.started')->count());
        // Re-running the progress hook never duplicates.
        app(\App\Services\Community\CommunityEvents::class)->tournamentProgress($t);
        $this->assertSame(4, CommunityNotification::where('type', 'tournament.round')->count());
    }

    public function test_submission_flow_notifies_opponent_organizer_and_publishes_feed_to_participants_only(): void
    {
        [$t, $owner, $users] = $this->arena();
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $match = $t->matches()->where('status', 'pending')->orderBy('id')->first();
        $one = $users[array_search($match->playerOne->profile->account_user_id, array_map(fn ($u) => $u->id, $users))];
        $two = $users[array_search($match->playerTwo->profile->account_user_id, array_map(fn ($u) => $u->id, $users))];
        $outsider = User::factory()->create();

        $this->actingAs($one)->postJson("/tournaments/{$t->id}/matches/{$match->id}/submissions", ['player_one_games' => 2, 'player_two_games' => 1, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertTrue(CommunityNotification::where('user_id', $two->id)->where('type', 'match.submitted')->exists());
        $this->assertFalse(CommunityNotification::where('user_id', $one->id)->where('type', 'match.submitted')->exists());
        $this->actingAs($two)->get('/dashboard')->assertOk()->assertSee('Confirmar placar');

        $submission = \App\Models\MatchResultSubmission::sole();
        $this->actingAs($two)->postJson("/match-submissions/{$submission->id}/respond", ['action' => 'dispute', 'reason' => 'Foi 2 a 0'])->assertOk();
        $this->assertTrue(CommunityNotification::where('user_id', $owner->id)->where('type', 'match.disputed')->exists());
        $this->actingAs($owner)->get('/dashboard')->assertSee('Resultados para revisar');

        $this->actingAs($owner)->postJson("/match-submissions/{$submission->id}/resolve", ['player_one_games' => 2, 'player_two_games' => 0, 'reason' => 'Conferido na mesa'])->assertOk();
        $this->assertSame(1, CommunityActivity::where('type', 'match.confirmed')->count());
        $this->assertTrue(CommunityNotification::where('user_id', $one->id)->where('type', 'match.confirmed')->exists());

        $this->actingAs($two)->get('/feed')->assertOk()->assertSee('2×0');
        $this->actingAs($owner)->get('/feed')->assertOk()->assertSee('2×0');
        $this->actingAs($outsider)->get('/feed')->assertOk()->assertDontSee('2×0')->assertDontSee('Copa Integrada');
        // Player notifications stay readable for participants (not hidden by organizer-only policy).
        $this->actingAs($two)->get('/notifications')->assertSee('Organizador definiu');
    }

    public function test_muting_a_category_silences_all_its_event_types(): void
    {
        [$t, $owner, $users] = $this->arena();
        NotificationPreference::create(['user_id' => $users[1]->id, 'enabled' => true, 'muted_types' => ['tournament']]);
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $this->assertFalse(CommunityNotification::where('user_id', $users[1]->id)->exists());
        $this->assertTrue(CommunityNotification::where('user_id', $users[2]->id)->exists());
    }

    public function test_finishing_publishes_champion_once(): void
    {
        [$t, $owner, $users] = $this->arena('single_elimination');
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        for ($guard = 0; $guard < 6 && $t->fresh()->status !== 'finished'; $guard++) {
            foreach ($t->matches()->where('status', 'pending')->get() as $m) {
                $this->put(route('matches.update', [$t, $m]), ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
            }
            $round = (int) $t->matches()->max('round');
            $this->post(route('tournaments.advance', $t), ['round' => $round]);
        }
        $this->assertSame('finished', $t->fresh()->status);
        $activity = CommunityActivity::where('type', 'tournament.finished')->sole();
        $this->assertStringContainsString('venceu Copa Integrada', $activity->title);
        $this->assertSame(4, CommunityNotification::where('type', 'tournament.finished')->count());
    }

    public function test_elimination_submission_rejects_draw_before_confirmation(): void
    {
        [$t, $owner, $users] = $this->arena('single_elimination');
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $m = $t->matches()->where('status', 'pending')->first();
        $player = User::find($m->playerOne->profile->account_user_id);
        $this->actingAs($player)->postJson("/tournaments/{$t->id}/matches/{$m->id}/submissions", ['player_one_games' => 1, 'player_two_games' => 1, 'idempotency_key' => (string) Str::uuid()])->assertStatus(422);
        $this->assertDatabaseCount('match_result_submissions', 0);
    }

    public function test_challenges_notify_both_sides_and_completion_reaches_feed(): void
    {
        $a = User::factory()->create(); $b = User::factory()->create();
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);
        $s = app(ChallengeService::class);
        $c = $s->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $this->assertTrue(CommunityNotification::where('user_id', $b->id)->where('type', 'challenge.received')->exists());
        $this->actingAs($b)->get('/dashboard')->assertSee('Desafio recebido');
        $s->transition($c, $b, 'accept');
        $this->assertTrue(CommunityNotification::where('user_id', $a->id)->where('title', 'like', '%aceitou%')->exists());
        $s->report($c, $a, 2, 0);
        $this->actingAs($b)->get('/dashboard')->assertSee('Confirmar placar do desafio');
        $s->review($c, $b, 'confirm', 1);
        $this->actingAs($a)->get('/feed')->assertSee('2×0');
        $this->actingAs(User::factory()->create())->get('/feed')->assertDontSee('2×0');
    }

    public function test_club_organizer_can_resolve_disputes_but_plain_member_cannot(): void
    {
        [$t, $owner, $users] = $this->arena();
        $club = app(\App\Services\Community\ClubService::class)->create($owner, ['name' => 'Loja', 'privacy' => 'private']);
        $organizer = User::factory()->create(); $member = User::factory()->create();
        $club->members()->attach($organizer, ['role' => 'organizer']);
        $club->members()->attach($member, ['role' => 'member']);
        $t->club_id = $club->id; $t->save();
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $m = $t->matches()->where('status', 'pending')->first();
        $one = User::find($m->playerOne->profile->account_user_id);
        $two = User::find($m->playerTwo->profile->account_user_id);
        $this->actingAs($one)->postJson("/tournaments/{$t->id}/matches/{$m->id}/submissions", ['player_one_games' => 2, 'player_two_games' => 1, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $s = \App\Models\MatchResultSubmission::sole();
        $this->actingAs($two)->postJson("/match-submissions/{$s->id}/respond", ['action' => 'dispute', 'reason' => 'Errado'])->assertOk();
        $data = ['player_one_games' => 2, 'player_two_games' => 0, 'reason' => 'Conferido'];
        $this->actingAs($member)->postJson("/match-submissions/{$s->id}/resolve", $data)->assertForbidden();
        $this->actingAs($organizer)->postJson("/match-submissions/{$s->id}/resolve", $data)->assertOk();
        $this->assertSame('finished', $m->fresh()->status);
    }

    public function test_every_commander_pod_seat_sees_its_table_in_my_matches(): void
    {
        [$t, $owner, $users] = $this->arena('commander_pods', 8, ['round_limit' => 2]);
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $pod = $t->matches()->first();
        $this->assertCount(4, $pod->pod_player_ids);
        $fourth = User::find(\App\Models\Player::find($pod->pod_player_ids[3])->profile->account_user_id);
        $this->actingAs($fourth)->get('/me/matches')->assertOk()->assertSee('Mesa Commander')->assertSee('o organizador lança as posições');
        $placements = array_combine($pod->pod_player_ids, [4, 3, 2, 1]);
        $this->actingAs($owner)->put(route('matches.update', [$t, $pod]), ['placements' => $placements])->assertRedirect();
        $this->actingAs($fourth)->get('/me/matches')->assertSee('você ficou em 1º');
    }

    public function test_navigation_and_organizer_page_link_every_module(): void
    {
        [$t, $owner] = $this->arena('commander_pods', 8, ['round_limit' => 2]);
        $page = $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk();
        foreach (['/dashboard', '/me/matches', '/challenges', '/me/collection', '/feed', '/notifications'] as $path) $page->assertSee(url($path), false);
        $page->assertSee('Exibir QR em tela cheia')->assertSee('Modo TV');
        $this->post(route('tournaments.start', $t));
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee('Salvar posições')->assertSee('Mesa do organizador')->assertDontSee('Exibir QR em tela cheia');
        $this->post('/logout');
        $this->post('/login', ['email' => $owner->email, 'password' => 'Password123'])->assertRedirect('/dashboard');
    }
}
