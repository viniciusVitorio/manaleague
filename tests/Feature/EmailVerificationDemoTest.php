<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dados locais ficticios continuam utilizaveis sem caixa postal real. */
class EmailVerificationDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_accounts_can_access_the_app_without_real_email_delivery(): void
    {
        $this->seed(DemoSeeder::class);
        $users = User::where('email', 'like', '%@manaleague.test')->get();
        $this->assertGreaterThan(1, $users->count());
        foreach ($users as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk();
        }
    }
}
