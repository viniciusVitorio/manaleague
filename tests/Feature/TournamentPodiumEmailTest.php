<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\GameMatch;
use App\Models\NotificationPreference;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentPodiumEmail;
use App\Models\User;
use App\Notifications\TournamentPodiumNotification;
use App\Services\PairingService;
use App\Services\PlayerStatsService;
use App\Services\PodiumEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** E-mail de pódio: vai para 1º, 2º e 3º com o resultado no torneio, uma vez só, e respeita quem não deve receber. */
class TournamentPodiumEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_finishing_emails_the_podium_once_with_stats_prizes_and_link(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [
            1 => ['boosters' => 3, 'booster_label' => 'Bloomburrow', 'style' => 'trophy', 'trophy_label' => 'Taça da Loja'],
            0 => ['boosters' => 1, 'style' => ''],
        ]]);
        $this->finish($t);
        $this->assertNotNull($t->fresh()->finished_at);

        [$first, $second, $third, $fourth] = $this->finalOrder($t);
        foreach ([[$first, 1], [$second, 2], [$third, 3]] as [$player, $placement]) {
            Notification::assertSentToTimes($player->profile->account, TournamentPodiumNotification::class, 1);
            $this->assertDatabaseHas('tournament_podium_emails', ['tournament_id' => $t->id, 'user_id' => $player->profile->account_user_id, 'placement' => $placement, 'attempts' => 1]);
        }
        Notification::assertNotSentTo($fourth->profile->account, TournamentPodiumNotification::class);
        Notification::assertNotSentTo($owner, TournamentPodiumNotification::class);

        // As estatísticas contam a final: 3 partidas da liga + 1.
        $account = $first->profile->account;
        $podium = Notification::sent($account, TournamentPodiumNotification::class)->first()->podium;
        [$wins, $losses] = $this->record($t, $first);
        $this->assertSame([1, 4, $wins, $losses, 0, (int) round($wins / 4 * 100)], [$podium['placement'], $podium['played'], $podium['wins'], $podium['losses'], $podium['draws'], $podium['winrate']]);
        $this->assertSame(['Taça da Loja', '3 boosters de Bloomburrow', '1 booster'], $podium['prizes']);

        $mail = (new TournamentPodiumNotification($podium))->toMail($account);
        $this->assertSame('🥇 1º lugar em Copa da Loja — ManaLeague', $mail->subject);
        $html = $mail->render();
        foreach (['Você ficou em 1º lugar!', 'Copa da Loja', "{$wins}–{$losses}", 'Taxa de vitória', 'Taça da Loja', route('arena.public', $t->public_slug), route('prizes.index'), route('profiles.edit').'#notificacoes'] as $text) {
            $this->assertStringContainsString($text, (string) $html);
        }

        // Repetir o encerramento e a tarefa diária não manda de novo.
        app(PodiumEmailService::class)->schedule($t->fresh());
        app(PodiumEmailService::class)->deliverPending();
        $this->artisan('tournaments:podium-emails')->assertSuccessful();
        Notification::assertSentTimes(TournamentPodiumNotification::class, 3);
        $this->assertSame(3, TournamentPodiumEmail::count());
    }

    public function test_correcting_the_final_only_emails_who_changed_placement_and_never_twice(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        $this->actingAs($owner);
        $this->finish($t);
        [$first, $second] = $this->finalOrder($t);
        $final = $t->matches()->where('stage', 'final')->sole();
        $original = $final->only(['player_one_games', 'player_two_games']);

        // O vice vira campeão: os dois recebem a colocação nova; o 3º não recebe nada.
        $this->put(route('matches.update', [$t, $final]), ['player_one_games' => 0, 'player_two_games' => 2])->assertRedirect();
        Notification::assertSentTimes(TournamentPodiumNotification::class, 5);
        $this->assertSame(2, Notification::sent($second->profile->account, TournamentPodiumNotification::class)->count());
        $this->assertSame(1, Notification::sent($second->profile->account, TournamentPodiumNotification::class)->last()->podium['placement']);
        $this->assertSame(2, Notification::sent($first->profile->account, TournamentPodiumNotification::class)->last()->podium['placement']);

        // Voltar ao placar original não repete o que cada um já recebeu.
        $this->put(route('matches.update', [$t, $final]), $original)->assertRedirect();
        Notification::assertSentTimes(TournamentPodiumNotification::class, 5);
    }

    public function test_placement_changed_before_sending_drops_the_old_email(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        $this->actingAs($owner);
        $this->finish($t);
        [$first] = $this->finalOrder($t);
        TournamentPodiumEmail::query()->delete();
        $stale = TournamentPodiumEmail::create(['tournament_id' => $t->id, 'user_id' => $first->profile->account_user_id, 'placement' => 3]);
        Notification::fake();

        app(PodiumEmailService::class)->deliverPending();

        Notification::assertNothingSent();
        $this->assertModelMissing($stale);
    }

    public function test_unconfirmed_email_off_and_accountless_players_get_nothing(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        [$unconfirmed, $emailOff, $guest, $alsoOff] = $t->players()->with('profile.account')->orderBy('id')->get()->all();
        $unconfirmed->profile->account->forceFill(['email_verified_at' => null, 'email_verification_required' => true])->save();
        foreach ([$emailOff, $alsoOff] as $player) {
            NotificationPreference::create(['user_id' => $player->profile->account_user_id, 'enabled' => true, 'muted_types' => [], 'email_enabled' => false]);
        }
        $guest->update(['player_profile_id' => null]);

        $this->actingAs($owner);
        $this->finish($t);

        Notification::assertNothingSent();
        $this->assertSame(0, TournamentPodiumEmail::count());
    }

    public function test_email_switch_is_separate_from_the_in_app_notifications(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        // Sino desligado e Torneios silenciado não mexem no e-mail: quem deixou o e-mail ligado recebe.
        foreach ($t->players()->with('profile')->get() as $index => $player) {
            NotificationPreference::create(['user_id' => $player->profile->account_user_id, 'enabled' => $index % 2 === 0, 'muted_types' => ['tournament'], 'email_enabled' => true]);
        }

        $this->actingAs($owner);
        $this->finish($t);

        Notification::assertSentTimes(TournamentPodiumNotification::class, 3);
    }

    public function test_account_from_before_email_confirmation_still_receives(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        foreach ($t->players()->with('profile.account')->get() as $player) {
            $player->profile->account->forceFill(['email_verified_at' => null, 'email_verification_required' => false])->save();
        }
        $this->actingAs($owner);
        $this->finish($t);

        Notification::assertSentTimes(TournamentPodiumNotification::class, 3);
    }

    public function test_correcting_a_tournament_finished_before_this_feature_sends_nothing(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        $this->actingAs($owner);
        $this->finish($t);
        $t->forceFill(['finished_at' => null])->save();
        TournamentPodiumEmail::query()->delete();
        Notification::fake();

        $final = $t->matches()->where('stage', 'final')->sole();
        $this->put(route('matches.update', [$t, $final]), ['player_one_games' => 0, 'player_two_games' => 2])->assertRedirect();

        Notification::assertNothingSent();
        $this->assertSame(0, TournamentPodiumEmail::count());
    }

    public function test_failed_send_keeps_the_result_and_the_daily_task_retries_it(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [
            'transport' => 'smtp', 'scheme' => 'smtp', 'host' => '127.0.0.1', 'port' => 1,
            'username' => null, 'password' => null, 'timeout' => 1,
        ]]);
        Log::spy();
        [$t, $owner] = $this->arena();
        $this->actingAs($owner);
        $this->finish($t);

        // O torneio encerrou normalmente; nada saiu e nada ficou preso.
        $this->assertSame(Tournament::STATUS_FINISHED, $t->fresh()->status);
        $this->assertSame(3, TournamentPodiumEmail::whereNull('sent_at')->whereNull('claimed_at')->where('attempts', 1)->count());
        Log::shouldHaveReceived('warning')->with('tournament.podium_mail_failed', \Mockery::on(fn (array $context) => ! array_key_exists('email', $context)))->times(3);

        $this->artisan('tournaments:podium-emails')->assertFailed();
        $this->assertSame(3, TournamentPodiumEmail::where('attempts', 2)->count());

        Notification::fake();
        $this->artisan('tournaments:podium-emails')->assertSuccessful();
        Notification::assertSentTimes(TournamentPodiumNotification::class, 3);
        $this->assertSame(3, TournamentPodiumEmail::whereNotNull('sent_at')->count());
    }

    public function test_retries_stop_at_the_limit_after_the_window_and_on_a_send_that_may_have_gone_out(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        $this->actingAs($owner);
        $this->finish($t);
        [$first, $second, $third] = $this->finalOrder($t);
        TournamentPodiumEmail::query()->update(['sent_at' => null, 'claimed_at' => null]);
        Notification::fake();

        $email = fn (Player $player) => TournamentPodiumEmail::where('user_id', $player->profile->account_user_id);
        $email($first)->update(['attempts' => PodiumEmailService::MAX_ATTEMPTS]);
        $email($second)->update(['created_at' => now()->subDays(PodiumEmailService::RETRY_DAYS + 1)]);
        // Processo morreu no meio do envio: não dá para saber se saiu, então não repete.
        $email($third)->update(['claimed_at' => now()->subDay()]);

        $this->assertSame(['sent' => 0, 'failed' => 0], app(PodiumEmailService::class)->deliverPending());
        Notification::assertNothingSent();
    }

    public function test_names_written_by_the_organizer_cannot_become_html_or_links(): void
    {
        Notification::fake();
        $name = "<b>Copa</b> [clique](https://golpe.test)\nBcc: alguem@golpe.test";
        [$t, $owner] = $this->arena(['name' => $name]);
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['style' => 'trophy', 'trophy_label' => '<i>Taça</i> [x](https://golpe.test)']]]);
        $this->finish($t);

        [$first] = $this->finalOrder($t);
        $account = $first->profile->account;
        $mail = Notification::sent($account, TournamentPodiumNotification::class)->first()->toMail($account);
        $html = (string) $mail->render();

        $this->assertStringNotContainsString("\n", $mail->subject);
        $this->assertStringContainsString('&lt;b&gt;Copa&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;Taça&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('href="https://golpe.test', $html);
    }

    public function test_commander_pods_show_table_results_without_a_games_score(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena(['system' => 'commander_pods', 'format' => 'Commander', 'round_limit' => 1]);
        $this->actingAs($owner)->post(route('tournaments.start', $t))->assertRedirect();
        $pod = $t->matches()->sole();
        $placements = collect($pod->pod_player_ids)->values()->mapWithKeys(fn ($id, $index) => [$id => $index + 1])->all();
        $this->put(route('matches.update', [$t, $pod]), ['placements' => $placements])->assertRedirect();
        $this->post(route('tournaments.advance', $t), ['round' => 1])->assertRedirect();

        Notification::assertSentTimes(TournamentPodiumNotification::class, 3);
        $winner = Player::with('profile.account')->findOrFail($pod->pod_player_ids[0]);
        $podium = Notification::sent($winner->profile->account, TournamentPodiumNotification::class)->first()->podium;
        $this->assertSame([1, 1, 0, 100, null], [$podium['played'], $podium['wins'], $podium['losses'], $podium['winrate'], $podium['games']]);
    }

    public function test_pod_elimination_emails_the_podium_and_counts_the_organizer_tiebreak(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena(['system' => 'pod_elimination', 'format' => 'Commander', 'max_players' => 8, 'pod_random_draw' => false]);
        foreach (range(5, 8) as $i) {
            $profile = User::factory()->create()->ensurePlayerProfile();
            $deck = $profile->decks()->create(['name' => "Deck {$i}", 'format' => 'Commander']);
            $t->players()->create(['name' => "Jogador {$i}", 'player_profile_id' => $profile->id, 'deck_id' => $deck->id]);
        }
        $t->players()->update(['checked_in_at' => now()]);
        Deck::whereIn('id', $t->players()->pluck('deck_id'))->update(['format' => 'Commander']);
        $this->actingAs($owner);
        app(PairingService::class)->generateLeague($t);
        $semis = $t->matches()->get();
        foreach ($semis as $pod) {
            $this->put(route('matches.update', [$t, $pod]), ['placements' => array_combine($pod->pod_player_ids, [1, 2, 3, 4]), 'confirm' => 1])->assertSessionHasNoErrors();
        }
        // Empate no título, decidido pelo organizador: quem ficou em 2º no desempate não ganhou a final.
        $final = $t->matches()->where('stage', 'final')->sole();
        $this->put(route('matches.update', [$t, $final]), ['placements' => array_combine($final->pod_player_ids, [1, 1, 3, 4]),
            'tiebreak' => array_combine($final->pod_player_ids, [1, 2, 3, 4]), 'resolution_note' => 'Desempate jogado na mesa.', 'confirm' => 1])->assertSessionHasNoErrors();

        $this->assertSame(Tournament::STATUS_FINISHED, $t->fresh()->status);
        Notification::assertSentTimes(TournamentPodiumNotification::class, 3);
        $second = Player::with('profile.account')->findOrFail($final->pod_player_ids[1]);
        $semiWin = (int) ($t->matches()->where('stage', 'league')->get()->first(fn ($pod) => in_array($second->id, $pod->pod_player_ids, true))->pod_scores[$second->id] === 1);
        $podium = Notification::sent($second->profile->account, TournamentPodiumNotification::class)->first()->podium;
        $this->assertSame([2, 2, $semiWin, 2 - $semiWin, null], [$podium['placement'], $podium['played'], $podium['wins'], $podium['losses'], $podium['games']]);
    }

    public function test_tournament_records_count_a_bye_as_a_win_like_the_profile(): void
    {
        [$t, $owner] = $this->arena(['system' => 'swiss', 'round_limit' => 1]);
        // Cinco inscritos: alguém fica de BYE na rodada.
        $t->players()->create(['name' => 'Jogador 5']);
        $this->actingAs($owner)->post(route('tournaments.start', $t))->assertRedirect();
        $bye = $t->matches()->where('status', GameMatch::STATUS_BYE)->sole();

        $record = app(PlayerStatsService::class)->tournamentRecords($t)[$bye->player_one_id];

        $this->assertSame([1, 1, 0, 100], [$record['played'], $record['wins'], $record['losses'], $record['winrate']]);
    }

    public function test_tournament_email_can_be_turned_off_alone(): void
    {
        Notification::fake();
        [$t, $owner] = $this->arena();
        foreach ($t->players()->with('profile')->get() as $player) {
            NotificationPreference::create(['user_id' => $player->profile->account_user_id, 'enabled' => true, 'muted_types' => [], 'email_enabled' => true, 'email_muted_types' => ['tournament']]);
        }

        $this->actingAs($owner);
        $this->finish($t);

        Notification::assertNothingSent();
    }

    public function test_settings_have_a_notifications_section_with_app_and_email_per_category(): void
    {
        $user = User::factory()->create();
        $user->ensurePlayerProfile();

        // Sem preferência gravada, tudo ligado. E-mail só onde existe; o resto fica "Só no app".
        $html = $this->actingAs($user)->get(route('profiles.edit'))->assertOk()
            ->assertSee('href="#notificacoes"', false)
            ->assertSeeInOrder(['id="notificacoes"', 'App', 'E-mail', 'Todas as notificações', 'Torneios', 'Partidas', 'Só no app', 'Salvar notificações'], false)
            ->assertSee('name="email_enabled" value="1" aria-label="Todas as notificações por e-mail" checked', false)
            ->assertSee('data-mute="email.tournament" checked', false)
            ->assertDontSee('data-mute="email.match"', false)
            ->getContent();
        $this->assertSame(6, substr_count($html, 'Só no app'));

        // Desligar só o e-mail de Torneios grava e volta desligado.
        $this->patch(route('notifications.preferences'), ['enabled' => 1, 'muted_types' => [], 'email_enabled' => 1, 'email_muted_types' => ['tournament']])->assertRedirect();
        $this->assertSame(['tournament'], NotificationPreference::where('user_id', $user->id)->value('email_muted_types'));
        $this->get(route('profiles.edit'))->assertSee('data-mute="email.tournament" >', false);

        // Desligar o canal inteiro de e-mail.
        $this->patch(route('notifications.preferences'), ['enabled' => 1, 'muted_types' => [], 'email_enabled' => 0])->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'enabled' => true, 'email_enabled' => false]);

        // Formulário sem os campos de e-mail (página antiga) não liga o e-mail de volta.
        $this->patch(route('notifications.preferences'), ['enabled' => 0, 'muted_types' => ['club']])->assertRedirect();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'enabled' => false, 'email_enabled' => false]);

        $this->patch(route('notifications.preferences'), ['enabled' => 1, 'email_enabled' => 'talvez'])->assertSessionHasErrors('email_enabled');
        $this->patch(route('notifications.preferences'), ['enabled' => 1, 'email_enabled' => 1, 'email_muted_types' => ['<script>']])->assertSessionHasErrors('email_muted_types.0');
    }

    public function test_notifications_page_keeps_only_the_inbox_and_links_to_the_settings(): void
    {
        $this->actingAs(User::factory()->create())->get(route('notifications.index'))->assertOk()
            ->assertSee('Caixa de entrada')
            ->assertSee('Configurar notificações')->assertSee(route('profiles.edit').'#notificacoes', false)
            ->assertDontSee('Salvar preferências')->assertDontSee('data-mute=', false);
    }

    public function test_registration_saves_the_email_choice_checked_by_default(): void
    {
        $this->get(route('register'))->assertOk()
            ->assertSee('name="email_updates" value="1" checked', false)
            ->assertSee('Aceito receber e-mails do ManaLeague, como avisos, novidades e estatísticas de partidas.');

        $form = ['name' => 'Ana', 'password' => 'Secure123', 'password_confirmation' => 'Secure123'];
        $this->post('/register', [...$form, 'email' => 'ana@example.com', 'email_updates' => '1'])->assertRedirect('/email/verify');
        $this->post('/logout');
        $this->post('/register', [...$form, 'email' => 'bia@example.com'])->assertRedirect('/email/verify');

        $this->assertDatabaseHas('notification_preferences', ['user_id' => User::where('email', 'ana@example.com')->value('id'), 'email_enabled' => true, 'enabled' => true]);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => User::where('email', 'bia@example.com')->value('id'), 'email_enabled' => false, 'enabled' => true]);
    }

    public function test_registration_form_keeps_the_unchecked_email_choice_after_an_error(): void
    {
        $this->from(route('register'))->post('/register', ['name' => 'Ana', 'email' => 'nao-e-email', 'password' => 'Secure123', 'password_confirmation' => 'Secure123'])
            ->assertSessionHasErrors('email');
        $this->get(route('register'))->assertOk()->assertDontSee('name="email_updates" value="1" checked', false);
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

    /** @return list<Player> campeão, vice, 3º e 4º, pelas partidas decisivas */
    private function finalOrder(Tournament $t): array
    {
        $order = [];
        foreach (['final', 'third_place'] as $stage) {
            $match = $t->matches()->where('stage', $stage)->with(['playerOne.profile.account', 'playerTwo.profile.account'])->sole();
            array_push($order, ...($match->player_one_games > $match->player_two_games ? [$match->playerOne, $match->playerTwo] : [$match->playerTwo, $match->playerOne]));
        }

        return $order;
    }

    /** @return array{0: int, 1: int} vitórias e derrotas do jogador, contadas direto das partidas */
    private function record(Tournament $t, Player $player): array
    {
        $wins = $losses = 0;
        foreach ($t->matches()->where('status', GameMatch::STATUS_FINISHED)->get() as $match) {
            if (! in_array($player->id, [$match->player_one_id, $match->player_two_id], true)) {
                continue;
            }
            $won = ($match->player_one_id === $player->id) === ($match->player_one_games > $match->player_two_games);
            $won ? $wins++ : $losses++;
        }

        return [$wins, $losses];
    }

    /** Torneio de 4 inscritos com conta e deck. */
    private function arena(array $extra = []): array
    {
        $owner = User::factory()->create();
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
