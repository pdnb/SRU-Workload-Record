<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupervisorRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_review_route_is_not_registered(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $this->actingAs($user)
            ->get('/supervisor/review')
            ->assertNotFound();
    }

    public function test_migration_converts_supervisor_role_to_staff(): void
    {
        DB::table('users')->insert([
            'name' => 'Former Supervisor',
            'email' => 'former-supervisor@example.com',
            'password' => bcrypt('password'),
            'role' => 'supervisor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (include database_path('migrations/2026_06_22_000001_migrate_supervisor_users_to_staff.php'))->up();

        $user = User::where('email', 'former-supervisor@example.com')->first();

        $this->assertSame('staff', $user->role->value);

        $this->actingAs($user)
            ->get('/home')
            ->assertRedirect(route('staff.dashboard'));
    }
}
