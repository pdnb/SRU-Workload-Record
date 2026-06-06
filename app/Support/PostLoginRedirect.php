<?php

namespace App\Support;

use App\Models\User;

class PostLoginRedirect
{
    public static function routeFor(User $user): string
    {
        $route = match (true) {
            $user->isStaff() => 'staff.records.index',
            $user->isSupervisor() => 'supervisor.review.index',
            default => 'hr.dashboard',
        };

        return route($route, absolute: false);
    }
}
