<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContextualTournamentFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_format_query_does_not_crash_the_creation_form(): void
    {
        $this->actingAs(User::factory()->create())->get(route('tournaments.create', ['format' => ['Commander']]))->assertOk();
    }

    public function test_duel_formats_offer_only_four_generic_modes(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['Pauper', 'Standard', 'Modern', 'Draft', 'Outro'] as $format) {
            $html = $this->get(route('tournaments.create', ['format' => $format]))->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $choices = $xpath->query('//input[@name="system" and not(@disabled)]');
            $this->assertSame(['round_robin', 'swiss', 'single_elimination', 'double_elimination'], array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($choices)));
            $this->assertCount(1, $xpath->query('//fieldset[@data-round-settings and @disabled and @hidden]'));
            $summary = $xpath->query('//*[@data-rules-summary]')->item(0)->textContent;
            $this->assertStringContainsString($format, $summary);
            $this->assertSame($format === 'Pauper', str_contains($summary, 'como comum'));
        }
    }

    public function test_editing_legacy_commander_duels_preserves_the_exact_saved_system_and_ranked(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        foreach (['round_robin', 'swiss', 'single_elimination', 'double_elimination'] as $system) {
            $tournament = $owner->tournaments()->create(['name' => 'Legacy '.$system, 'format' => 'Commander', 'system' => $system, 'ranked' => true, 'tournament_date' => today(), 'public_token' => Str::random(40), 'invite_token' => Str::random(40)]);
            $html = $this->get(route('tournaments.edit', $tournament))->assertOk()->assertSee('configuração atual')->assertDontSee('@else', false)->getContent();
            $xpath = $this->xpath($html);
            $this->assertSame($system, $xpath->query('//input[@name="system" and @checked]')->item(0)->getAttribute('value'));
            $this->assertCount(1, $xpath->query('//input[@name="ranked" and @type="checkbox" and @checked and not(@disabled)]'));
            $this->put(route('tournaments.update', $tournament), ['name' => 'Renamed legacy', 'format' => 'Commander', 'system' => $system, 'ranked' => '1', 'tournament_date' => today()->toDateString()])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($system, $tournament->fresh()->system);
            $this->assertTrue($tournament->fresh()->ranked);
        }
    }

    public function test_validation_old_input_retains_explicit_points_mode_and_custom_settings(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->from(route('tournaments.create'))->post(route('tournaments.store'), ['name' => '', 'format' => 'Commander', 'system' => 'commander_pods', 'round_limit' => 5, 'max_players' => 12, 'rules' => 'custom', 'house_rules' => 'Proxies liberados', 'allow_banned' => 1])->assertSessionHasErrors('name');
        $html = $this->get(route('tournaments.create'))->assertOk()->assertSee('Proxies liberados')->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame('commander_pods', $xpath->query('//input[@name="system" and @checked]')->item(0)->getAttribute('value'));
        $this->assertCount(1, $xpath->query('//input[@name="round_limit" and @value="5"]'));
        $this->assertCount(1, $xpath->query('//input[@name="max_players" and @value="12"]'));
        $this->assertCount(1, $xpath->query('//details[@data-advanced-settings and @open]'));
        $this->assertCount(1, $xpath->query('//fieldset[@data-custom-rules and not(@disabled) and not(@hidden)]'));
        $this->assertCount(1, $xpath->query('//input[@name="ranked" and @type="checkbox" and @disabled]'));
    }

    public function test_legacy_can_deliberately_migrate_to_multiplayer_without_stale_ranked(): void
    {
        $owner = User::factory()->create();
        $tournament = $owner->tournaments()->create(['name' => 'Old duel', 'format' => 'Commander', 'system' => 'swiss', 'ranked' => true, 'tournament_date' => today(), 'public_token' => Str::random(40), 'invite_token' => Str::random(40)]);
        $this->actingAs($owner)->put(route('tournaments.update', $tournament), ['name' => 'Multiplayer', 'format' => 'Commander', 'system' => 'commander_pods', 'ranked' => 0, 'round_limit' => 3, 'tournament_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('commander_pods', $tournament->fresh()->system);
        $this->assertFalse($tournament->fresh()->ranked);
    }

    public function test_async_advanced_validation_marks_only_error_groups_for_reopening(): void
    {
        $this->actingAs(User::factory()->create());
        $create = route('tournaments.create');
        $this->from($create)->withHeader('X-ManaLeague-Async', '1')
            ->post(route('tournaments.store'), [
                'name' => 'Invalid ranked rules', 'format' => 'Pauper', 'system' => 'swiss',
                'tournament_date' => today()->toDateString(), 'ranked' => 1,
                'rules' => 'custom', 'allow_banned' => 1,
            ])->assertOk()->assertJson(['redirect' => $create])->assertSessionHasErrors('allow_banned');
        $xpath = $this->xpath($this->get($create)->assertOk()->getContent());
        $this->assertCount(1, $xpath->query('//details[@data-advanced-settings and @data-async-open-errors and @open]'));

        $this->flushSession();
        $xpath = $this->xpath($this->withSession(['_old_input' => ['rules' => 'custom']])->get($create)->assertOk()->getContent());
        $this->assertCount(1, $xpath->query('//details[@data-advanced-settings and @open]'));
        $this->assertCount(0, $xpath->query('//details[@data-async-open-errors]'));
    }

    public function test_commander_ban_guidance_explains_ranked_restrictions_only_for_legacy_duels(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $hint = 'Torneios ranqueados não aceitam banidas.';
        foreach ([false, true] as $ranked) {
            $tournament = $owner->tournaments()->create(['name' => 'Legacy ranked guidance', 'format' => 'Commander', 'system' => 'swiss', 'ranked' => $ranked, 'tournament_date' => today(), 'public_token' => Str::random(40), 'invite_token' => Str::random(40)]);
            $xpath = $this->xpath($this->get(route('tournaments.edit', $tournament))->assertOk()->getContent());
            $this->assertStringContainsString($hint, $xpath->query('//*[@data-ban-hint]')->item(0)->textContent);
        }
        $xpath = $this->xpath($this->get(route('tournaments.create', ['format' => 'Commander']))->assertOk()->getContent());
        $this->assertStringNotContainsString($hint, $xpath->query('//*[@data-ban-hint]')->item(0)->textContent);
        foreach (['commander_pods', 'pod_elimination'] as $system) {
            $html = $this->withSession(['_old_input' => ['format' => 'Commander', 'system' => $system, 'ranked' => 1]])->get(route('tournaments.create'))->assertOk()->getContent();
            $this->assertStringNotContainsString($hint, $this->xpath($html)->query('//*[@data-ban-hint]')->item(0)->textContent);
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_consolation_is_disabled_in_initial_markup_outside_eight_top_two(): void
    {
        $this->actingAs(User::factory()->create());
        $html = $this->withSession(['_old_input' => ['format' => 'Commander', 'system' => 'pod_elimination', 'max_players' => 16, 'pod_qualifiers' => 2]])->get(route('tournaments.create'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//fieldset[@data-consolation-setting and @disabled and @hidden]'));
    }

    public function test_commander_rules_do_not_display_pauper_or_draft_explanations(): void
    {
        $this->actingAs(User::factory()->create());
        $html = $this->get(route('tournaments.create', ['format' => 'Commander']))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $rules = $xpath->query('//*[@data-rules-copy]')->item(0);
        $this->assertNotNull($rules);
        $this->assertStringContainsString('Commander', $rules->textContent);
        $this->assertStringNotContainsString('Pauper', $rules->textContent);
        $this->assertStringNotContainsString('Draft', $rules->textContent);
        $this->assertCount(1, $xpath->query('//fieldset[@data-custom-rules and @disabled and @hidden]'));
    }

    public function test_commander_creation_offers_two_multiplayer_choices_and_defaults_to_elimination(): void
    {
        $this->actingAs(User::factory()->create());
        $html = $this->get(route('tournaments.create', ['format' => 'Commander']))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $choices = $xpath->query('//input[@name="system" and not(@disabled)]');
        $this->assertCount(2, $choices);
        $this->assertSame(['commander_pods', 'pod_elimination'], array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($choices)));
        $this->assertSame('pod_elimination', $xpath->query('//input[@name="system" and @checked]')->item(0)?->getAttribute('value'));
        $this->assertStringContainsString('40 de vida', $html);
        $this->assertStringContainsString('data-tournament-form', $html);
        $this->assertCount(1, $xpath->query('//details[@data-advanced-settings]'));
        $this->assertCount(0, $xpath->query('//details[@data-advanced-settings and @open]'));
    }
}
