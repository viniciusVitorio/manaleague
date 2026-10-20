<?php
namespace Tests\Feature;

use App\Models\User;
use App\Models\Club;
use App\Services\Community\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Illuminate\Support\Facades\Route::has('clubs.index') && file_exists(base_path('routes/community.php'))) {
            \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/community.php'));
        }
    }

    public function test_club_ui_roles_membership_and_tournament_isolation(): void
    {
        $owner=User::factory()->create(); $member=User::factory()->create(); $stranger=User::factory()->create();
        $club=app(ClubService::class)->create($owner,['name'=>'Clube privado','privacy'=>'private']);
        $this->actingAs($stranger)->get('/clubs/'.$club->id)->assertForbidden();
        $this->actingAs($owner)->post('/clubs/'.$club->id.'/members',['email'=>$member->email])->assertRedirect();
        $this->actingAs($member)->get('/clubs/'.$club->id)->assertOk()->assertSee('Clube privado');
        $this->post('/clubs/'.$club->id.'/tournaments',['name'=>'Copa','format'=>'Pauper','tournament_date'=>'2026-10-20'])->assertForbidden();
        $this->actingAs($owner)->patch('/clubs/'.$club->id.'/members/'.$member->id,['role'=>'organizer'])->assertRedirect();
        $this->actingAs($member)->post('/clubs/'.$club->id.'/tournaments',['name'=>'Copa','format'=>'Pauper','tournament_date'=>'2026-10-20'])->assertRedirect();
        $tournament=\App\Models\Tournament::where('club_id',$club->id)->sole();
        $this->assertTrue($owner->can('update',$tournament));
        $this->assertTrue($member->can('update',$tournament));
        $this->actingAs($owner)->delete('/clubs/'.$club->id.'/members/'.$member->id)->assertRedirect();
        $this->assertFalse($member->can('update',$tournament));
        $this->assertFalse($member->can('view',$tournament));
        $this->delete('/clubs/'.$club->id.'/members/'.$owner->id)->assertStatus(409);
        $this->get('/clubs')->assertOk();
    }

    public function test_notifications_are_idempotent_private_and_preferences_apply(): void
    {
        $a=User::factory()->create();$b=User::factory()->create();
        $service=app(\App\Services\Community\NotificationService::class);
        $service->send($a,'club','Convite de clube','/clubs','club:1');
        $service->send($a,'club','Convite de clube','/clubs','club:1');
        $this->assertDatabaseCount('community_notifications',1);
        $id=\App\Models\CommunityNotification::sole()->id;
        $this->actingAs($b)->patch('/notifications/'.$id.'/read')->assertNotFound();
        $this->get('/notifications')->assertOk()->assertDontSee('Convite de clube');
        $this->actingAs($a)->get('/notifications')->assertOk()->assertSee('Convite de clube');
        $this->patch('/notifications/'.$id.'/read')->assertRedirect();
        $this->assertNotNull(\App\Models\CommunityNotification::find($id)->read_at);
        $this->patch('/notifications/preferences',['enabled'=>false,'muted_types'=>['club']])->assertRedirect();
        $service->send($a,'club','Silenciosa','/clubs','club:2');
        $this->assertDatabaseCount('community_notifications',1);
        $this->get('/dashboard')->assertOk()->assertSee('Meu painel');
    }

    public function test_feed_deduplicates_and_checks_current_access_even_for_actor(): void
    {
        $owner=User::factory()->create(); $member=User::factory()->create(); $outsider=User::factory()->create();
        $club=app(ClubService::class)->create($owner,['name'=>'Secret circle','privacy'=>'private']);
        $club->members()->attach($member,['role'=>'member']);
        $service=app(\App\Services\Community\ActivityService::class);
        $service->record($member,'club.test','Private feed event',$club,'club:test:1');
        $service->record($member,'club.test','Private feed event',$club,'club:test:1');
        $this->assertDatabaseCount('community_activities',1);
        $this->actingAs($member)->get('/feed')->assertOk()->assertSee('Private feed event');
        $this->actingAs($outsider)->get('/feed')->assertOk()->assertDontSee('Private feed event');
        $club->members()->detach($member);
        $this->actingAs($member)->get('/feed')->assertOk()->assertDontSee('Private feed event');
        $club->update(['privacy'=>'public']);
        $this->get('/feed')->assertSee('Private feed event');
        $club->update(['privacy'=>'private']);
        $this->get('/feed')->assertDontSee('Private feed event');
    }

    public function test_notifications_revoke_private_subject_access_immediately(): void
    {
        $owner=User::factory()->create(); $member=User::factory()->create();
        $club=app(ClubService::class)->create($owner,['name'=>'Hidden club','privacy'=>'private']);
        $club->members()->attach($member,['role'=>'member']);
        app(\App\Services\Community\NotificationService::class)->send($member,'club','Secret invitation','/clubs/'.$club->id,'club:invite',['club_id'=>$club->id]);
        $id=\App\Models\CommunityNotification::sole()->id;
        $this->actingAs($member)->get('/notifications')->assertSee('Secret invitation');
        $club->members()->detach($member);
        $this->get('/notifications')->assertDontSee('Secret invitation');
        $this->patch('/notifications/'.$id.'/read')->assertNotFound();
        $this->get('/dashboard')->assertSee('0 não lidas');
    }

    public function test_club_creation_assigns_owner_and_private_clubs_are_isolated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $club = app(ClubService::class)->create($owner, ['name'=>'Mesa secreta','privacy'=>'private']);
        $this->assertSame('owner', $club->roleFor($owner));
        $this->assertTrue($owner->can('view', $club));
        $this->assertFalse($other->can('view', $club));
        $this->assertFalse($other->can('manage', $club));
    }
}
