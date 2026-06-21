<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendancePolicy;
use App\Models\User;
use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\CarbonInterface;

class DbPolicyResolver implements PolicyResolver
{
    /** @var array<int, ?User> Per-instance user lookup cache (avoids re-querying the same user per day). */
    private array $userCache = [];

    /** @var array<string, \Illuminate\Support\Collection> Per-instance active-policy candidates keyed by date. */
    private array $candidatesByDate = [];

    public function resolve(int $userId, CarbonInterface $date): PolicyProfile
    {
        // Memoize within the resolver instance so callers that resolve in a loop
        // (e.g. the monthly report over user x day) don't issue a User::find +
        // AttendancePolicy::active() query on every single call. The bound resolver
        // is per-app() (not a singleton), so the cache lives only for that request's
        // usage and never leaks across requests.
        $user = $this->userCache[$userId] ??= User::find($userId);
        $d = $date->toDateString();

        $candidates = $this->candidatesByDate[$d] ??= AttendancePolicy::active()
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
            breaks: $match->rule_overrides['breaks'] ?? null,
            overtime: $match->rule_overrides['overtime'] ?? null,
        );
    }
}
