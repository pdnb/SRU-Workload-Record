<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('oracle:sync')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('oracle_workload.enabled'));
