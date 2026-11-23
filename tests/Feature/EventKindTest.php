<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Eventos: cada um é campeonato (pontos corridos) ou torneio (mata-mata), pelo sistema de disputa. */
class EventKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_system_belongs_to_exactly_one_kind(): void
    {
        $kinds = collect(Tournament::KINDS)->map(fn (array $kind) => $kind[2]);
        $this->assertEqualsCanonicalizing(array_keys(Tournament::SYSTEMS), $kinds->flatten()->all());
        $this->assertSame([], array_intersect($kinds['championship'], $kinds['tournament']));

        foreach (['round_robin', 'swiss', 'commander_pods'] as $system) {
            $this->assertSame(['championship', 'Campeonato', 'campeonato'], $this->kindOf($system));
        }
        foreach (['single_elimination', 'double_elimination', 'pod_elimination'] as $system) {
            $this->assertSame(['tournament', 'Torneio', 'torneio'], $this->kindOf($system));
        }
    }

    public function test_events_page_filters_by_kind_and_labels_each_card(): void
    {
        $user = User::factory()->create();
        $this->event($user, 'Liga de Quinta', 'round_robin');
        $this->event($user, 'Suíço de Sábado', 'swiss');
        $this->event($user, 'Copa Mata-Mata', 'single_elimination');
        $this->actingAs($user);

        $this->get(route('tournaments.index'))->assertOk()
            ->assertSee('Seus eventos')->assertSee('Novo evento')
            ->assertSeeInOrder(['Todos', '3', 'Campeonatos', '2', 'Torneios', '1'])
            ->assertSee('Liga de Quinta')->assertSee('Copa Mata-Mata')
            ->assertSee('<span class="ui-badge event-kind">Campeonato</span>', false)
            ->assertSee('<span class="ui-badge event-kind">Torneio</span>', false);

        $html = $this->get(route('tournaments.index', ['tipo' => 'torneios']))->assertOk()
            ->assertSee('Copa Mata-Mata')->assertDontSee('Liga de Quinta')->assertDontSee('Suíço de Sábado')->getContent();
        $this->assertMatchesRegularExpression('/tipo=torneios"\s+aria-current="page"/', $html);
        $this->get(route('tournaments.index', ['tipo' => 'campeonatos']))->assertOk()
            ->assertSee('Liga de Quinta')->assertSee('Suíço de Sábado')->assertDontSee('Copa Mata-Mata');
        // Filtro desconhecido volta para "Todos".
        $this->get(route('tournaments.index', ['tipo' => 'xyz']))->assertOk()->assertSee('Liga de Quinta')->assertSee('Copa Mata-Mata');

        // Sem nenhum do tipo, o vazio fala do tipo filtrado.
        $this->actingAs(User::factory()->create())->get(route('tournaments.index', ['tipo' => 'torneios']))
            ->assertSee('Nenhum torneio criado')->assertSee('Você ainda não está em nenhum torneio');
    }

    public function test_form_asks_for_the_kind_and_explains_each_system(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('tournaments.create'))->assertOk()
            ->assertSee('Novo evento')->assertSee('← Eventos')
            ->assertSeeInOrder(['Sobre o evento', 'Nome do evento', 'Disputa', 'Tipo de evento', 'Campeonato', 'Torneio', 'Sistema',
                'Liga (todos contra todos)', Tournament::SYSTEM_HINTS['round_robin'], 'Suíço', 'Pods Commander',
                'Eliminação simples', 'Dupla eliminação', 'Eliminação por Pods', 'Só no formato Commander', 'Regras do evento', 'Criar evento'])
            ->assertSee('name="kind" value="championship" checked', false)
            ->assertSee('name="system" value="round_robin" data-kind="championship"', false)
            ->getContent();
        // Começa em Campeonato: os três sistemas de mata-mata vêm escondidos e Eliminação por Pods, travada (formato Pauper).
        $this->assertSame(3, preg_match_all('/<label class="event-choice" data-system-choice\s+hidden\s*>/', $html));
        $this->assertMatchesRegularExpression('/value="pod_elimination" data-kind="tournament"\s+data-commander-only\s+disabled/', $html);
        // Rodadas só aparecem no Suíço e nos Pods; as opções de pods, na Eliminação por Pods.
        $this->assertMatchesRegularExpression('/data-rounds-setting\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/data-pod-elimination-settings\s+hidden/', $html);
    }

    public function test_a_knockout_event_is_called_torneio_and_a_league_campeonato(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('tournaments.store'), [
            'name' => 'Copa de Sexta', 'format' => 'Pauper', 'system' => 'single_elimination', 'kind' => 'tournament',
            'tournament_date' => today()->toDateString(),
        ])->assertSessionHas('status', 'Torneio criado. Agora chame a galera!');
        $cup = Tournament::where('name', 'Copa de Sexta')->sole();

        $this->get(route('tournaments.show', $cup))->assertOk()
            ->assertSee('<span class="ui-badge event-kind">Torneio</span>', false)
            ->assertSee('← Eventos')->assertSee('Editar torneio')->assertSee('Começar torneio')->assertDontSee('Editar campeonato')->assertDontSee('Começar campeonato');
        $this->get(route('tournaments.edit', $cup))->assertOk()
            ->assertSee('Editar torneio')->assertSee('name="kind" value="tournament" checked', false);
        $this->get(route('arena.public', $cup->public_slug))->assertOk()->assertSee('<span class="ui-badge event-kind">Torneio</span>', false);

        $league = $this->event($user, 'Liga de Quinta', 'round_robin');
        $this->get(route('tournaments.show', $league))->assertOk()
            ->assertSee('<span class="ui-badge event-kind">Campeonato</span>', false)
            ->assertSee('Editar campeonato')->assertSee('Começar campeonato');
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function kindOf(string $system): array
    {
        $t = new Tournament(['system' => $system]);

        return [$t->kind(), $t->kindLabel(), $t->kindNoun()];
    }

    private function event(User $owner, string $name, string $system): Tournament
    {
        return $owner->tournaments()->create([
            'name' => $name, 'format' => 'Pauper', 'system' => $system, 'tournament_date' => today(),
            'public_token' => Str::random(40), 'public_slug' => Str::slug($name).'-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
    }
}
