<?php

namespace App\Policies;

use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;

class EvaluationPeriodPolicy
{
    public function manage(User $user, EvaluationPeriod|FiscalYear $model): bool
    {
        return $user->isAdmin();
    }
}
