<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_admin_can_impersonate_staff_and_log_is_written(): void
    {
        $admin = $this->user(UserRole::Admin);
        $staff = $this->user(UserRole::Staff);

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $staff))
            ->assertRedirect(route('staff.dashboard', absolute: false));

        $this->assertAuthenticatedAs($staff);
        $this->assertSame($admin->id, session('impersonator_id'));
        $this->assertDatabaseHas('impersonation_logs', [
            'impersonator_id' => $admin->id,
            'impersonated_id' => $staff->id,
            'ended_at' => null,
        ]);
    }

    public function test_admin_can_impersonate_hr(): void
    {
        $hr = $this->user(UserRole::Hr);

        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('admin.users.impersonate', $hr))
            ->assertRedirect(route('hr.dashboard', absolute: false));

        $this->assertAuthenticatedAs($hr);
    }

    public function test_non_admins_cannot_start_impersonation(): void
    {
        $target = $this->user(UserRole::Staff);

        foreach ([UserRole::Staff, UserRole::Hr] as $role) {
            $this->actingAs($this->user($role))
                ->post(route('admin.users.impersonate', $target))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('impersonation_logs', 0);
    }

    public function test_admin_cannot_impersonate_self_or_another_admin(): void
    {
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $admin))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $this->user(UserRole::Admin)))
            ->assertForbidden();

        $this->assertDatabaseCount('impersonation_logs', 0);
    }

    public function test_admin_routes_are_forbidden_while_impersonating(): void
    {
        $staff = $this->user(UserRole::Staff);

        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('admin.users.impersonate', $staff));

        $this->get(route('admin.users'))->assertForbidden();
    }

    public function test_nested_impersonation_is_blocked(): void
    {
        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('admin.users.impersonate', $this->user(UserRole::Hr)));

        $this->post(route('admin.users.impersonate', $this->user(UserRole::Staff)))
            ->assertForbidden();
    }

    public function test_leave_restores_admin_and_closes_log(): void
    {
        $admin = $this->user(UserRole::Admin);
        $staff = $this->user(UserRole::Staff);

        $this->actingAs($admin)->post(route('admin.users.impersonate', $staff));

        $this->post(route('impersonate.leave'))
            ->assertRedirect(route('admin.users'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
        $this->assertNotNull(ImpersonationLog::first()->ended_at);
    }

    public function test_leave_without_impersonation_is_forbidden(): void
    {
        $this->actingAs($this->user(UserRole::Staff))
            ->post(route('impersonate.leave'))
            ->assertForbidden();
    }

    public function test_logout_while_impersonating_closes_log(): void
    {
        $this->actingAs($this->user(UserRole::Admin))
            ->post(route('admin.users.impersonate', $this->user(UserRole::Staff)));

        $this->post(route('logout'));

        $this->assertGuest();
        $this->assertNotNull(ImpersonationLog::first()->ended_at);
    }

    public function test_banner_is_shown_only_while_impersonating(): void
    {
        $admin = $this->user(UserRole::Admin);
        $staff = $this->user(UserRole::Staff);

        $this->actingAs($admin)->get(route('admin.users'))
            ->assertDontSee('กำลังสวมรอยเป็น');

        $this->post(route('admin.users.impersonate', $staff));

        $this->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee('กำลังสวมรอยเป็น '.$staff->name);
    }
}
