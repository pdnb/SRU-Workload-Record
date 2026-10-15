<?php

namespace App\Livewire\Actions;

use App\Models\ImpersonationLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke()
    {
        ImpersonationLog::close(Session::get('impersonation_log_id'));

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return redirect('/');
    }
}
