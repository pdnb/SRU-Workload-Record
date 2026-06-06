<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_via_home(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/home');
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_authenticated_staff_are_redirected_to_records(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response->assertRedirect('/home');
        $this->get('/home')->assertRedirect(route('staff.records.index'));
    }
}
