<?php

namespace App\Providers;

use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DefaultScheduleResolver;
use Illuminate\Support\ServiceProvider;

class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 0: schedule from global settings. Phase 1 rebinds to RosterScheduleResolver.
        $this->app->bind(ScheduleResolver::class, DefaultScheduleResolver::class);
    }
}
