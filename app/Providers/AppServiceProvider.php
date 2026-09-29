<?php

namespace App\Providers;

use App\Contracts\WorkloadSourceReader;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\WorkloadGroup;
use App\Models\WorkloadShare;
use App\Models\WorkRecord;
use App\Policies\EvaluationPeriodPolicy;
use App\Policies\WorkloadSharePolicy;
use App\Policies\WorkloadStructurePolicy;
use App\Policies\WorkRecordPolicy;
use App\Services\Oracle\OracleWorkloadReader;
use App\Socialite\Providers\UAppOAuthProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WorkloadSourceReader::class, OracleWorkloadReader::class);
    }

    public function boot(): void
    {
        /** @var SocialiteManager $socialite */
        $socialite = $this->app->make(SocialiteFactory::class);

        $socialite->extend('uapp', fn ($app) => $socialite->buildProvider(
            UAppOAuthProvider::class,
            $app['config']['services.uapp']
        ));

        Gate::policy(WorkRecord::class, WorkRecordPolicy::class);
        Gate::policy(WorkloadShare::class, WorkloadSharePolicy::class);

        Gate::define('manageRecordWorkload', function (User $user, WorkRecord $record, int $workloadId): bool {
            return app(WorkloadSharePolicy::class)->manageRecordWorkload($user, $record, $workloadId);
        });
        Gate::policy(EvaluationPeriod::class, EvaluationPeriodPolicy::class);
        Gate::policy(FiscalYear::class, EvaluationPeriodPolicy::class);
        Gate::policy(WorkloadGroup::class, WorkloadStructurePolicy::class);
    }
}
