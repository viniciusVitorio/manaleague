<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendListVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_opt_in_and_only_accepted_friends_can_see_friend_list(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $owner->ensurePlayerProfile();
        $friend = User::factory()->create();
        $friendProfile = $friend->ensurePlayerProfile();
        $listedFriend = User::factory()->create();
        $listedFriendProfile = $listedFriend->ensurePlayerProfile();
        $stranger = User::factory()->create();
        $stranger->ensurePlayerProfile();

        $owner->friendProfiles()->attach($friendProfile, ['status' => 'accepted']);
        $friend->friendProfiles()->attach($ownerProfile, ['status' => 'accepted']);
        $owner->friendProfiles()->attach($listedFriendProfile, ['status' => 'accepted']);
        $listedFriend->friendProfiles()->attach($ownerProfile, ['status' => 'accepted']);

        $this->actingAs($friend)->get(route('profiles.show', $ownerProfile))
            ->assertOk()->assertDontSee($listedFriendProfile->nickname);
        $this->actingAs($stranger)->get(route('profiles.show', $ownerProfile))->assertForbidden();

        $this->actingAs($owner)->patch(route('friends.list-privacy'), ['friends_list_visible' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($ownerProfile->fresh()->friends_list_visible);

        $this->actingAs($friend)->get(route('profiles.show', $ownerProfile))
            ->assertOk()->assertSee($listedFriendProfile->nickname);

        $this->actingAs($owner)->patch(route('friends.list-privacy'), ['friends_list_visible' => '0'])
            ->assertRedirect();
        $this->actingAs($friend)->get(route('profiles.show', $ownerProfile))
            ->assertOk()->assertDontSee($listedFriendProfile->nickname);
    }
}
