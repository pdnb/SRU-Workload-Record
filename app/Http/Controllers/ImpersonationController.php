<?php

namespace App\Http\Controllers;

use App\Models\ImpersonationLog;
use App\Models\User;
use App\Support\PostLoginRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin->isAdmin(), 403);
        abort_if($request->session()->has('impersonator_id'), 403, 'กำลังสวมรอยอยู่แล้ว');
        abort_if($user->is($admin) || $user->isAdmin(), 403, 'ไม่สามารถสวมรอยผู้ใช้นี้ได้');

        $log = ImpersonationLog::create([
            'impersonator_id' => $admin->id,
            'impersonated_id' => $user->id,
            'started_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'impersonator_id' => $admin->id,
            'impersonation_log_id' => $log->id,
        ]);

        return redirect(PostLoginRedirect::routeFor($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('impersonator_id');
        abort_if($adminId === null, 403);

        $admin = User::find($adminId);
        abort_unless($admin?->isAdmin(), 403);

        ImpersonationLog::close($request->session()->get('impersonation_log_id'));

        Auth::login($admin);
        $request->session()->regenerate();
        $request->session()->forget(['impersonator_id', 'impersonation_log_id']);

        return redirect()->route('admin.users');
    }
}
