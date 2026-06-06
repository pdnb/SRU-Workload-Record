<?php

namespace App\Providers;

use App\Socialite\Providers\UAppOAuthProvider;
use App\Models\EvaluationPeriod;
use App\Models\FiscalYear;
use App\Models\WorkRecord;
use App\Models\WorkloadGroup;
use App\Policies\EvaluationPeriodPolicy;
use App\Policies\WorkRecordPolicy;
use App\Policies\WorkloadStructurePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
        Gate::policy(EvaluationPeriod::class, EvaluationPeriodPolicy::class);
        Gate::policy(FiscalYear::class, EvaluationPeriodPolicy::class);
        Gate::policy(WorkloadGroup::class, WorkloadStructurePolicy::class);
    }
}
