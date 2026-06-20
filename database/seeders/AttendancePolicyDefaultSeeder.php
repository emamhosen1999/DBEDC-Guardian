<?php

namespace Database\Seeders;

use App\Models\HRM\AttendancePolicy;
use Illuminate\Database\Seeder;

class AttendancePolicyDefaultSeeder extends Seeder
{
    public function run(): void
    {
        if (AttendancePolicy::forScope('org', null)->exists()) {
            return;
        }
        AttendancePolicy::create([
            'name' => 'Global Default', 'scope_type' => 'org', 'scope_id' => null, 'priority' => 0,
            'effective_from' => now()->startOfYear()->toDateString(), 'version_group_id' => 1, 'version' => 1,
            'status' => 'active', 'punch_strictness' => 'warn', 'outside_window_minutes' => 120,
        ]);
    }
}
