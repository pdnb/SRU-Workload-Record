<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class UappAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.uapp.client_id' => 'test-client-id',
            'services.uapp.client_secret' => 'test-client-secret',
            'services.uapp.redirect' => 'http://localhost/auth/uapp/callback',
            'services.uapp.authorize_url' => 'https://uapp.example.com/oauth/authorize',
            'services.uapp.token_url' => 'https://uapp.example.com/oauth/token',
            'services.uapp.userinfo_url' => 'https://uapp.example.com/oauth/userinfo',
            'services.uapp.allowed_domain' => 'sru.ac.th',
        ]);
    }

    public function test_uapp_redirect_route_redirects_to_authorize_url(): void
    {
        Socialite::fake('uapp');

        $response = $this->get(route('auth.uapp.redirect'));

        $response->assertRedirect('https://socialite.fake/uapp/authorize');
    }

    public function test_uapp_callback_creates_user_and_logs_in_for_allowed_domain(): void
    {
        Socialite::fake('uapp', $this->fakeOAuthUser(
            id: 'sub-new-user',
            email: 'newstaff@sru.ac.th',
            name: 'บุคลากรใหม่',
        ));

        $response = $this->get(route('auth.uapp.callback'));

        $response->assertRedirect(route('staff.records.index', absolute: false));
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'newstaff@sru.ac.th',
            'oauth_sub' => 'sub-new-user',
            'role' => UserRole::Staff->value,
        ]);
    }

    public function test_uapp_callback_logs_in_existing_user_without_changing_role(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@sru.ac.th',
            'role' => UserRole::Admin,
            'oauth_sub' => 'sub-admin',
        ]);

        Socialite::fake('uapp', $this->fakeOAuthUser(
            id: 'sub-admin',
            email: 'admin@sru.ac.th',
            name: 'ผู้ดูแลระบบ',
        ));

        $response = $this->get(route('auth.uapp.callback'));

        $response->assertRedirect(route('hr.dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_uapp_callback_rejects_email_outside_allowed_domain(): void
    {
        Socialite::fake('uapp', $this->fakeOAuthUser(
            id: 'sub-external',
            email: 'user@gmail.com',
            name: 'External User',
        ));

        $response = $this->get(route('auth.uapp.callback'));

        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'user@gmail.com',
        ]);
    }

    protected function fakeOAuthUser(string $id, string $email, string $name): SocialiteUser
    {
        return (new SocialiteUser)->setRaw([
            'sub' => $id,
            'email' => $email,
            'name' => $name,
        ])->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
        ]);
    }
}
