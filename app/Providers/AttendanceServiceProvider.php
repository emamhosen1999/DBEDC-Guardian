<?php

namespace App\Providers;

use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DbPolicyResolver;
use App\Services\Attendance\RosterScheduleResolver;
use Illuminate\Support\ServiceProvider;

class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 1: schedule backed by roster, with settings fallback for users with no roster coverage.
        $this->app->bind(ScheduleResolver::class, RosterScheduleResolver::class);

        // Phase 3: policy resolution with scope precedence (user > designation > department > org).
        $this->app->bind(PolicyResolver::class, DbPolicyResolver::class);
    }
}
