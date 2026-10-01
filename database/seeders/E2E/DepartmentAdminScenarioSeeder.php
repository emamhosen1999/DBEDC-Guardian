<?php

namespace Database\Seeders\E2E;

use App\Models\HRM\Asset;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\Offboarding;
use App\Models\HRM\Onboarding;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Models\WorkLocation;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * ISOLATED end-to-end fixture for tests/playwright/department-admin.spec.js — never run against a real
 * database. Two departments (Inspection has NO designations, like production), work locations with
 * linked terminals, a Department Admin in Inspection (Mahdi), three employees per department, an HR
 * Manager, company-wide and Operations-owned shifts/patterns, leaves, assets, an onboarding and an
 * offboarding per department.
 *
 * Every account's password is "Passw0rd!E2E".
 */
class DepartmentAdminScenarioSeeder extends Seeder
{
    public const PASSWORD = 'Passw0rd!E2E';

    public function run(): void
    {
        $this->call(ComprehensiveRolePermissionSeeder::class);

        $inspection = Department::create(['name' => 'Inspection', 'code' => 'INS', 'is_active' => true]);
        $operations = Department::create(['name' => 'Operations CANARY-D2-7f3a', 'code' => 'OPS', 'is_active' => true]);
        $opsHead = Designation::create(['title' => 'Ops Manager CANARY-D2-7f3a', 'department_id' => $operations->id, 'hierarchy_level' => 1, 'is_active' => true]);
        $opsOfficer = Designation::create(['title' => 'Ops Officer CANARY-D2-7f3a', 'department_id' => $operations->id, 'hierarchy_level' => 2, 'parent_id' => $opsHead->id, 'is_active' => true]);

        $type = AttendanceType::firstOrCreate(['slug' => 'biometric'], ['name' => 'Biometric Terminal', 'icon' => 'B', 'description' => 'Fingerprint terminals', 'config' => [], 'priority' => 1, 'is_active' => true]);
        $gateA = BiometricDevice::create(['name' => 'Gate A', 'serial_number' => 'E2E-A', 'location' => 'Plaza A', 'is_active' => true]);
        $gateB = BiometricDevice::create(['name' => 'Gate B', 'serial_number' => 'E2E-B', 'location' => 'Plaza B', 'is_active' => true]);
        $retired = BiometricDevice::create(['name' => 'Retired Gate', 'serial_number' => 'E2E-R', 'location' => 'Old', 'is_active' => false]);
        $type->biometricDevices()->syncWithoutDetaching([$gateA->id, $gateB->id]);
        $plazaA = WorkLocation::create(['name' => 'Plaza A', 'code' => 'PA', 'is_active' => true]);
        $plazaB = WorkLocation::create(['name' => 'Plaza B', 'code' => 'PB', 'is_active' => true]);
        DB::table('work_location_biometric_device')->insert([
            ['work_location_id' => $plazaA->id, 'biometric_device_id' => $gateA->id],
            ['work_location_id' => $plazaA->id, 'biometric_device_id' => $retired->id],
            ['work_location_id' => $plazaB->id, 'biometric_device_id' => $gateB->id],
        ]);

        $mahdi = $this->user('1537', 'Mahdi Hasan', 'mahdi@e2e.test', $inspection, ['Department Admin']);
        $hr = $this->user('9001', 'Hana HR', 'hr@e2e.test', null, ['HR Manager', 'Employee']);
        $ins = [];
        foreach ([['2001', 'Ishrat Inspector'], ['2002', 'Imran Inspector'], ['2003', 'Ila Inspector']] as [$id, $name]) {
            $ins[] = $this->user($id, $name, "{$id}@e2e.test", $inspection, ['Employee', 'Daily Works Contributor'], null, $plazaA->id);
        }
        $ops = [];
        foreach ([['3001', 'Omar Ops CANARY-D2-7f3a'], ['3002', 'Oli Ops CANARY-D2-7f3a'], ['3003', 'Ona Ops CANARY-D2-7f3a']] as [$id, $name]) {
            $ops[] = $this->user($id, $name, "canary-d2-7f3a-{$id}@e2e.test", $operations, ['Employee', 'Daily Works Contributor'], $opsOfficer->id, $plazaB->id);
        }
        $opsAdmin = $this->user('3100', 'Olga Ops Admin CANARY-D2-7f3a', 'opsadmin@e2e.test', $operations, ['Department Admin']);

        // shifts + patterns: company-wide and Operations-owned
        $general = Shift::create(['name' => 'General', 'code' => 'GEN', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 60, 'grace_in_minutes' => 15, 'full_day_minutes' => 420, 'half_day_minutes' => 210, 'is_active' => true, 'created_by' => '9001', 'department_id' => null, 'color' => '#3b82f6']);
        $opsNight = Shift::create(['name' => 'Ops Night CANARY-D2-7f3a', 'code' => 'OPS-N', 'type' => 'fixed', 'start_time' => '21:00', 'end_time' => '05:00', 'crosses_midnight' => true, 'is_active' => true, 'created_by' => $opsAdmin->employee_id, 'department_id' => $operations->id, 'color' => '#7c3aed']);
        ShiftRotationPattern::create(['name' => 'Company 5-2', 'code' => 'C52', 'cycle_length_days' => 2, 'definition' => [$general->id, null], 'is_active' => true, 'created_by' => '9001', 'department_id' => null]);
        ShiftRotationPattern::create(['name' => 'Ops Nights CANARY-D2-7f3a', 'code' => 'OPSN', 'cycle_length_days' => 2, 'definition' => [$opsNight->id, null], 'is_active' => true, 'created_by' => $opsAdmin->employee_id, 'department_id' => $operations->id]);

        // leaves
        $annual = LeaveSetting::create(['type' => 'Annual', 'symbol' => 'AL', 'days' => 20, 'eligibility' => 0, 'carry_forward' => false, 'earned_leave' => false, 'is_earned' => false, 'is_paid' => true, 'requires_approval' => true, 'auto_approve' => false]);
        foreach ([$ins[0], $ops[0]] as $employee) {
            Leave::create(['user_id' => $employee->employee_id, 'leave_type' => $annual->id, 'from_date' => now()->addDays(10)->toDateString(), 'to_date' => now()->addDays(11)->toDateString(), 'no_of_days' => 2, 'status' => 'Pending', 'reason' => 'E2E leave']);
        }

        // assets, onboarding, offboarding per department (created_by comes from the acting user)
        auth()->setUser($hr);
        Asset::create(['asset_code' => 'AST-INS-1', 'name' => 'Inspection Laptop', 'category' => 'Laptop', 'status' => 'available']);
        Asset::create(['asset_code' => 'AST-OPS-1', 'name' => 'Ops Radio CANARY-D2-7f3a', 'category' => 'Radio', 'assignee_id' => $ops[0]->employee_id, 'assigned_date' => now()->toDateString(), 'status' => 'assigned']);
        Onboarding::create(['employee_id' => $ins[2]->employee_id, 'start_date' => now()->toDateString(), 'expected_completion_date' => now()->addWeek()->toDateString(), 'status' => 'in_progress']);
        Onboarding::create(['employee_id' => $ops[2]->employee_id, 'start_date' => now()->toDateString(), 'expected_completion_date' => now()->addWeek()->toDateString(), 'status' => 'in_progress']);
        Offboarding::create(['employee_id' => $ins[1]->employee_id, 'initiation_date' => now()->toDateString(), 'last_working_date' => now()->addDays(30)->toDateString(), 'status' => 'in_progress', 'reason' => 'Resignation']);
        Offboarding::create(['employee_id' => $ops[1]->employee_id, 'initiation_date' => now()->toDateString(), 'last_working_date' => now()->addDays(30)->toDateString(), 'status' => 'in_progress', 'reason' => 'Resignation']);
    }

    /** @param  array<int, string>  $roles */
    private function user(string $id, string $name, string $email, ?Department $department, array $roles, ?int $designationId = null, ?int $workLocationId = null): User
    {
        $user = new User(['employee_id' => $id, 'name' => $name, 'user_name' => "u{$id}", 'email' => $email, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill([
            'department_id' => $department?->id, 'designation_id' => $designationId, 'work_location_id' => $workLocationId,
            'email_verified_at' => now(), 'date_of_joining' => '2025-01-01', 'gender' => 'male',
        ])->save();
        $user->syncRoles($roles);

        return $user;
    }
}
