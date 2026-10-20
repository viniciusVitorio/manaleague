<?php
namespace Tests\Feature;

use App\Models\{Tournament, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveMatchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Illuminate\Support\Facades\Route::has('matches.mine') && file_exists(base_path('routes/live.php'))) {
            \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/live.php'));
        }
    }

    public function test_only_opponent_can_confirm_and_retries_are_idempotent(): void
    {
        [$t,$m,$owner,$one,$two] = $this->fixture();
        $this->actingAs($one)->postJson("/tournaments/{$t->id}/matches/{$m->id}/submissions", ['player_one_games'=>2,'player_two_games'=>1,'idempotency_key'=>(string) Str::uuid()])->assertCreated();
        $s = \App\Models\MatchResultSubmission::sole();
        $url = "/match-submissions/{$s->id}/respond";
        $this->postJson($url,['action'=>'confirm'])->assertForbidden();
        $this->actingAs($owner)->postJson($url,['action'=>'confirm'])->assertForbidden();
        $this->actingAs($two)->postJson($url,['action'=>'confirm'])->assertOk();
        $this->postJson($url,['action'=>'confirm'])->assertOk();
        $this->assertSame('finished',$m->fresh()->status);
        $this->assertDatabaseCount('match_result_submission_events',2);
    }

    public function test_dispute_requires_owner_resolution_with_audit_reason(): void
    {
        [$t,$m,$owner,$one,$two] = $this->fixture();
        $this->actingAs($one)->postJson("/tournaments/{$t->id}/matches/{$m->id}/submissions", ['player_one_games'=>2,'player_two_games'=>1,'idempotency_key'=>(string) Str::uuid()])->assertCreated();
        $s = \App\Models\MatchResultSubmission::sole();
        $this->actingAs($two)->postJson("/match-submissions/{$s->id}/respond",['action'=>'dispute','reason'=>'Placar invertido'])->assertOk();
        $data=['player_one_games'=>1,'player_two_games'=>2,'reason'=>'Conferido com os jogadores'];
        $this->postJson("/match-submissions/{$s->id}/resolve",$data)->assertForbidden();
        $this->actingAs($owner)->postJson("/match-submissions/{$s->id}/resolve",$data)->assertOk();
        $this->postJson("/match-submissions/{$s->id}/resolve",$data)->assertOk();
        $this->assertSame(1,$m->fresh()->player_one_games);
        $this->assertDatabaseCount('match_result_submission_events',3);
    }

    public function test_public_live_snapshot_and_tv_never_expose_private_tokens(): void
    {
        [$t,$m,$owner,$one] = $this->fixture();
        $this->actingAs($one)->postJson("/tournaments/{$t->id}/matches/{$m->id}/submissions", ['player_one_games'=>2,'player_two_games'=>1,'idempotency_key'=>(string) Str::uuid()])->assertCreated();
        $this->getJson('/t/'.$t->public_slug.'/live')->assertOk()->assertJsonPath('matches.0.one_score',null)->assertDontSee($t->invite_token)->assertDontSee($t->public_token)->assertDontSee($one->email);
        $this->get('/t/'.$t->public_slug.'/tv')->assertOk()->assertSee('Modo TV')->assertDontSee($t->invite_token)->assertDontSee('csrf-token')->assertDontSee($one->email);
        $this->get('/t/missing/live')->assertNotFound();
    }

    public function test_qr_is_owner_only_png_and_invite_auth_remembers_target(): void
    {
        [$t,$m,$owner,$one] = $this->fixture();
        $this->actingAs($one)->get('/tournaments/'.$t->id.'/registration-qr.png')->assertForbidden();
        $response=$this->actingAs($owner)->get('/tournaments/'.$t->id.'/registration-qr.png')->assertOk()->assertHeader('Content-Type','image/png');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n",$response->getContent());
        $this->get('/tournaments/'.$t->id.'/registration-qr')->assertOk()->assertSee('Inscrições');
        auth()->logout();
        $this->get('/join/'.$t->invite_token)->assertRedirect('/login');
        $this->assertSame(url('/join/'.$t->invite_token),session('url.intended'));
    }

    public function test_mobile_review_queue_and_audit_are_authorized_and_rendered(): void
    {
        [$t,$m,$owner,$one,$two]=$this->fixture();
        $this->actingAs($one)->postJson("/tournaments/{$t->id}/matches/{$m->id}/submissions",['player_one_games'=>2,'player_two_games'=>1,'idempotency_key'=>(string) Str::uuid()])->assertCreated();
        $this->actingAs($two)->get('/me/matches')->assertOk()->assertSee('Confirmar placar')->assertSee('Contestar');
        $this->get('/tournaments/'.$t->id.'/submissions')->assertForbidden();
        $this->actingAs($owner)->get('/tournaments/'.$t->id.'/submissions')->assertOk()->assertSee('Resolver e publicar')->assertSee('Auditoria')->assertSee('submitted');
        $this->get('/t/'.$t->public_slug)->assertOk()->assertSee('data-live-url',false)->assertSee('Modo TV');
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $one = User::factory()->create(); $two = User::factory()->create();
        $t = $owner->tournaments()->create(['name'=>'Live Cup','format'=>'Pauper','tournament_date'=>'2026-10-20','status'=>'league','public_token'=>Str::random(40),'public_slug'=>'live-cup','invite_token'=>Str::random(40)]);
        $p1 = $t->players()->create(['name'=>'One','player_profile_id'=>$one->ensurePlayerProfile()->id]);
        $p2 = $t->players()->create(['name'=>'Two','player_profile_id'=>$two->ensurePlayerProfile()->id]);
        $m = $t->matches()->create(['round'=>1,'stage'=>'league','status'=>'pending','player_one_id'=>$p1->id,'player_two_id'=>$p2->id]);
        return [$t,$m,$owner,$one,$two];
    }

    public function test_player_submits_once_without_publishing_unconfirmed_score(): void
    {
        [$t,$m,$owner,$one,$two] = $this->fixture();
        $url = "/tournaments/{$t->id}/matches/{$m->id}/submissions";
        $data = ['player_one_games'=>2,'player_two_games'=>1,'idempotency_key'=>(string) Str::uuid()];
        $this->actingAs($one)->postJson($url,$data)->assertCreated()->assertJsonPath('status','pending');
        $this->postJson($url,$data)->assertOk();
        $this->assertDatabaseCount('match_result_submissions',1);
        $this->assertSame('pending',$m->fresh()->status);
        $this->assertDatabaseCount('match_result_submission_events',1);
        $this->get('/me/matches')->assertOk()->assertSee('Aguardando confirmação');
    }
}
