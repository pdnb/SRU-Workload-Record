<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PostLoginRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class UAppAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('uapp')
            ->redirect();
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
        $domain = config('services.uapp.allowed_domain');

        if (! $email || ! Str::endsWith($email, '@'.$domain)) {
            return $this->redirectWithError('อนุญาตเฉพาะอีเมล @'.$domain.' เท่านั้น');
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $oauthUser->getName() ?? Str::before($email, '@'),
                'oauth_sub' => $oauthUser->getId(),
                'email_verified_at' => now(),
            ]
        );

        $user->refresh();

        Auth::login($user, remember: true);
        Session::regenerate();

        return redirect()->intended(PostLoginRedirect::routeFor($user));
    }

    protected function redirectWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['email' => $message]);
    }
}
