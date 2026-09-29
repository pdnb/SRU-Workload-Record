<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Oracle\SyncStaffPositions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PostLoginRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class UAppAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('uapp')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $oauthUser = Socialite::driver('uapp')->user();
        } catch (InvalidStateException) {
            return $this->redirectWithError('การยืนยันตัวตนไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        } catch (Throwable) {
            return $this->redirectWithError('ไม่สามารถเข้าสู่ระบบด้วยบัญชีมหาวิทยาลัยได้ กรุณาลองใหม่อีกครั้ง');
        }

        $email = Str::lower((string) $oauthUser->getEmail());

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $oauthUser->getName() ?? Str::before($email, '@'),
                'oauth_sub' => $oauthUser->getId(),
                'email_verified_at' => now(),
            ]
        );

        $user->refresh();

        $this->syncPositionFromOracle($user);

        Auth::login($user, remember: true);
        Session::regenerate();

        return redirect()->intended(PostLoginRedirect::routeFor($user));
    }

    /**
     * First login: pull the user's position from Oracle so they can record work
     * right away. Never blocks login — failures are only logged.
     */
    protected function syncPositionFromOracle(User $user): void
    {
        if (! config('oracle_workload.enabled') || $user->position_id !== null) {
            return;
        }

        try {
            app(SyncStaffPositions::class)->forUser($user);
        } catch (Throwable $e) {
            Log::warning('Oracle position sync on login failed.', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    protected function redirectWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['email' => $message]);
    }
}
