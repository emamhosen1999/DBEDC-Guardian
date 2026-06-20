<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\CarbonInterface;

class DbPolicyResolver implements PolicyResolver
{
    public function resolve(int $userId, CarbonInterface $date): PolicyProfile
    {
        $user = User::find($userId);
        $d = $date->toDateString();

        $candidates = AttendancePolicy::active()
            ->whereDate('effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d))
            ->get();

        // precedence: user > designation > department > org
        $order = ['user' => 4, 'designation' => 3, 'department' => 2, 'org' => 1];
        $match = $candidates
            ->filter(fn ($p) => match ($p->scope_type) {
                'user' => $p->scope_id === $userId,
                'designation' => $user && $p->scope_id === $user->designation_id,
                'department' => $user && $p->scope_id === $user->department_id,
                'org' => true,
                default => false,
            })
            ->sortByDesc(fn ($p) => [$order[$p->scope_type] ?? 0, $p->priority])
            ->first();

        if (! $match) {
            return PolicyProfile::neutral();
        }

        return new PolicyProfile(
            strictness: $match->punch_strictness,
            outsideWindowMinutes: $match->outside_window_minutes,
            graceTiers: $match->grace_tiers,
            rounding: $match->rounding,
        );
    }
}
