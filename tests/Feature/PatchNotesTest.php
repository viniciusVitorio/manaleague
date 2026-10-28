<?php

namespace Tests\Feature;

use App\Models\CommunityNotification;
use App\Models\NotificationPreference;
use App\Models\PatchNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatchNotesTest extends TestCase
{
    use RefreshDatabase;

    private function syncFile(array|string $releases): string
    {
        $path = tempnam(sys_get_temp_dir(), 'releases');
        file_put_contents($path, is_string($releases) ? $releases : json_encode($releases));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function release(string $tag, string $publishedAt, ?string $name = null, ?string $body = null): array
    {
        return ['tag' => $tag, 'name' => $name, 'body' => $body, 'published_at' => $publishedAt];
    }

    public function test_sync_creates_updates_and_removes_patch_notes(): void
    {
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v1.0.0', '2026-10-01T12:00:00Z', 'Lançamento', 'Primeira versão'),
            $this->release('v1.1.0', '2026-10-10T12:00:00Z', null, '- Decks na navegação'),
        ])])->expectsOutputToContain('2 novo(s), 0 atualizado(s), 0 removido(s)')->assertSuccessful();

        $this->assertSame(['v1.1.0', 'v1.0.0'], PatchNote::newestFirst()->pluck('version')->all());
        $this->assertSame('v1.1.0', PatchNote::where('version', 'v1.1.0')->value('title'), 'sem nome, o título vira a tag');

        // v1.0.0 editada, v1.1.0 removida no GitHub, v1.2.0 nova
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v1.0.0', '2026-10-01T12:00:00Z', 'Lançamento', 'Texto corrigido'),
            $this->release('v1.2.0', '2026-10-20T12:00:00Z', 'Patch notes', 'Nova aba'),
        ])])->expectsOutputToContain('1 novo(s), 1 atualizado(s), 1 removido(s)')->assertSuccessful();

        $this->assertSame(['v1.2.0', 'v1.0.0'], PatchNote::newestFirst()->pluck('version')->all());
        $this->assertSame('Texto corrigido', PatchNote::where('version', 'v1.0.0')->value('body'));
    }

    public function test_new_version_rings_the_bell_once_for_every_account(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create()->all();

        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v1.0.0', '2026-10-01T12:00:00Z', 'Lançamento'),
            $this->release('v1.1.0', '2026-10-10T12:00:00Z', 'Decks na navegação'),
        ])])->expectsOutputToContain('Aviso da versão v1.1.0 no sino de 2 conta(s).')->assertSuccessful();

        // importação inicial com várias releases: só a mais recente avisa
        $this->assertSame(['patch_note:v1.1.0'], CommunityNotification::where('user_id', $ana->id)->pluck('event_key')->all());
        $notification = CommunityNotification::where('user_id', $ana->id)->first();
        $this->assertSame('Nova versão v1.1.0: Decks na navegação', $notification->title);
        $this->assertSame('/patch-notes#v1.1.0', $notification->url);

        $this->actingAs($ana)->get('/patch-notes')->assertSee('Notificações, 1 não lidas');
        $this->get('/notifications')->assertSee('Nova versão v1.1.0: Decks na navegação')->assertSee('Novas versões do ManaLeague');

        // edição da release e nova sincronização não repetem o aviso
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v1.0.0', '2026-10-01T12:00:00Z', 'Lançamento'),
            $this->release('v1.1.0', '2026-10-10T12:00:00Z', 'Decks na navegação', 'Texto novo'),
        ])])->doesntExpectOutputToContain('Aviso')->assertSuccessful();
        $this->assertSame(2, CommunityNotification::count());

        // quem silenciou "Patch notes" não recebe a próxima
        NotificationPreference::create(['user_id' => $bia->id, 'enabled' => true, 'muted_types' => ['patch_note']]);
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v1.0.0', '2026-10-01T12:00:00Z', 'Lançamento'),
            $this->release('v1.1.0', '2026-10-10T12:00:00Z', 'Decks na navegação'),
            $this->release('v1.2.0', '2026-10-20T12:00:00Z'),
        ])])->expectsOutputToContain('Aviso da versão v1.2.0 no sino de 1 conta(s).')->assertSuccessful();
        $this->assertSame('Nova versão v1.2.0 no ar', CommunityNotification::where('user_id', $ana->id)->latest('id')->value('title'));
        $this->assertFalse(CommunityNotification::where('user_id', $bia->id)->where('event_key', 'patch_note:v1.2.0')->exists());

        // release antiga cadastrada depois não avisa; release apagada leva o aviso junto
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v0.9.0', '2026-09-01T12:00:00Z'),
            $this->release('v1.0.0', '2026-10-01T12:00:00Z', 'Lançamento'),
            $this->release('v1.1.0', '2026-10-10T12:00:00Z', 'Decks na navegação'),
        ])])->doesntExpectOutputToContain('Aviso')->assertSuccessful();
        $this->assertSame(['patch_note:v1.1.0'], CommunityNotification::where('user_id', $ana->id)->pluck('event_key')->all());
    }

    public function test_release_date_is_shown_in_the_app_timezone(): void
    {
        // 01:00 UTC do dia 10 ainda é dia 9 em São Paulo
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v1.0.0', '2026-10-10T01:00:00Z'),
        ])])->assertSuccessful();

        $this->assertTrue(PatchNote::first()->published_at->eq(\Illuminate\Support\Carbon::parse('2026-10-10T01:00:00Z')));
        $this->get('/patch-notes')->assertSee('09/10/2026');
    }

    public function test_invalid_input_changes_nothing(): void
    {
        PatchNote::create(['version' => 'v1.0.0', 'title' => 'Lançamento', 'published_at' => now()]);

        $this->artisan('patch-notes:sync', ['file' => $this->syncFile('{nao e json')])
            ->expectsOutputToContain('Nada foi alterado')->assertFailed();
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([
            $this->release('v2.0.0', '2026-10-20T12:00:00Z'),
            $this->release('tag com espaço', '2026-10-21T12:00:00Z'),
        ])])->expectsOutputToContain('tag ausente ou invalida')->assertFailed();
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile([['tag' => 'v2.0.0']])])
            ->expectsOutputToContain('published_at ausente')->assertFailed();
        $this->artisan('patch-notes:sync', ['file' => $this->syncFile(['tag' => 'v2.0.0'])])
            ->expectsOutputToContain('lista JSON')->assertFailed();
        $this->artisan('patch-notes:sync', ['file' => 'nao-existe.json'])->assertFailed();

        $this->assertSame(['v1.0.0'], PatchNote::pluck('version')->all());
    }

    public function test_page_lists_notes_newest_first_with_current_version(): void
    {
        PatchNote::create(['version' => 'v1.0.0', 'title' => 'Lançamento', 'body' => 'Primeira versão', 'published_at' => '2026-10-01 12:00:00']);
        PatchNote::create(['version' => 'v1.1.0', 'title' => 'Decks na navegação', 'body' => "## Novidades\n- Nova aba Decks\n- Vitrine com 3 decks\n\nObrigado por jogar!", 'published_at' => '2026-10-10 12:00:00']);

        $html = $this->get('/patch-notes')->assertOk()->getContent();

        foreach (['Patch notes', 'Versão atual v1.1.0', 'Decks na navegação', '<h3>Novidades</h3>', '<li>Nova aba Decks</li>', '<li>Vitrine com 3 decks</li>', '<p>Obrigado por jogar!</p>', '10/10/2026', 'Primeira versão'] as $needle) {
            $this->assertStringContainsString($needle, $html, 'faltou: '.$needle);
        }
        $this->assertLessThan(strpos($html, 'Lançamento'), strpos($html, 'Decks na navegação'), 'mais nova primeiro');
    }

    public function test_release_text_is_escaped(): void
    {
        PatchNote::create(['version' => 'v1.0.0', 'title' => '<b>Título</b>', 'body' => "<script>alert(1)</script>\n- <img src=x onerror=alert(1)>", 'published_at' => now()]);

        $this->get('/patch-notes')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;Título&lt;/b&gt;', false);
    }

    public function test_empty_state_and_menu_link(): void
    {
        $this->get('/patch-notes')->assertOk()->assertSee('Nenhum patch note publicado ainda');

        $user = User::factory()->create();
        $user->ensurePlayerProfile();
        $html = $this->actingAs($user)->get('/patch-notes')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#class="ui-menu-item" href="'.preg_quote(route('patch-notes.index'), '#').'"\s+aria-current="page"#', $html);
    }
}
