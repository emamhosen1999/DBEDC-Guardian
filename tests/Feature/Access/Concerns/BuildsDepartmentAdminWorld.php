<?php

namespace Tests\Feature\Access\Concerns;

use App\Models\HRM\Asset;
use App\Models\HRM\Attendance;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\HRM\Offboarding;
use App\Models\HRM\Onboarding;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One world for the access-audit tests: the REAL seeded roles and permissions (Department Admin =
 * its exact 52), a Department Admin of D1 (with the base Employee role, like production), D1 staff, and a
 * second department D2 whose every record carries a CANARY marker. The marker must never reach D1's admin.
 *
 * D2 records get distinctive ids (>= 90001) so a route called with such an id can only ever resolve to
 * a D2 record, whatever table the route reads.
 */
trait BuildsDepartmentAdminWorld
{
    public const MARKER = 'CANARY-D2-7f3a';

    public const DAY = '2026-06-03';

    protected Department $d1;

    protected Department $d2;

    protected User $admin;       // Department Admin of D1 (+ Employee)

    protected User $peer;        // another Department Admin in D1 (equal rank)

    protected User $hrInD1;      // HR Manager sitting in D1 (outranks him)

    protected User $hr;          // global HR

    protected User $e1;

    protected User $e1b;

    protected User $c1;          // D2 canary employees

    protected User $c2;

    protected User $d2Admin;

    /** @var array<string, string> a D2 id per resource kind, for parameterised routes */
    protected array $d2Ids = [];

    protected function buildWorld(): void
    {
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00:00'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(ComprehensiveRolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::where('name', 'Daily Works Contributor')->exists() || Role::create(['name' => 'Daily Works Contributor', 'guard_name' => 'web', 'hierarchy_level' => 60]);

        $this->d1 = Department::factory()->create(['name' => 'Inspection']);
        $this->d2 = new Department(['name' => 'Operations '.self::MARKER, 'code' => 'OPS-'.self::MARKER]);
        $this->d2->forceFill(['id' => 90001, 'is_active' => true])->save();

        $this->admin = $this->person('Dee One Admin', $this->d1, ['Department Admin', 'Employee'], '1537');
        $this->peer = $this->person('Peer Admin', $this->d1, ['Department Admin']);
        $this->hrInD1 = $this->person('Hr Inside D1', $this->d1, ['HR Manager']);
        $this->hr = $this->person('Global Hr', null, ['HR Manager', 'Employee']);
        $this->e1 = $this->person('Inspector One', $this->d1, ['Employee']);
        $this->e1b = $this->person('Inspector Two', $this->d1, ['Employee']);

        $this->c1 = $this->canary('90001', 'Zed '.self::MARKER, ['Employee']);
        $this->c2 = $this->canary('90002', 'Yan '.self::MARKER, ['Employee']);
        $this->d2Admin = $this->canary('90003', 'Dee Two Admin '.self::MARKER, ['Department Admin']);

        $this->canaryRecords();
    }

    protected function person(string $name, ?Department $department, array $roles, ?string $employeeId = null): User
    {
        $attrs = ['department_id' => $department?->id, 'name' => $name];
        if ($employeeId !== null) {
            $attrs['employee_id'] = $employeeId;
        }
        $user = User::factory()->create($attrs);
        $user->syncRoles($roles);

        return $user;
    }

    protected function canary(string $id, string $name, array $roles): User
    {
        $slug = strtolower(self::MARKER).'-'.$id;
        $user = User::factory()->create([
            'employee_id' => $id, 'name' => $name, 'email' => "{$slug}@example.com", 'user_name' => $slug,
            'phone' => '0199'.$id, 'department_id' => $this->d2->id,
        ]);
        $user->forceFill([
            'nid' => self::MARKER.'-NID-'.$id, 'address' => self::MARKER.' road', 'about' => self::MARKER.' bio',
            'bank_name' => self::MARKER.' bank', 'bank_account_no' => self::MARKER.'-ACC-'.$id,
            'emergency_contact_primary_name' => self::MARKER.' contact', 'salary_amount' => 91234,
        ])->save();
        $user->syncRoles($roles);

        return $user;
    }

    /** D2's records of every kind, each carrying the marker where there is a text field. */
    protected function canaryRecords(): void
    {
        $designation = new Designation(['title' => 'Chief '.self::MARKER, 'department_id' => 90001, 'hierarchy_level' => 1, 'is_active' => true]);
        $designation->forceFill(['id' => 90001])->save();
        $this->c1->forceFill(['designation_id' => 90001])->save();

        $shift = new Shift(['name' => 'Night '.self::MARKER, 'code' => 'N-90001', 'type' => 'fixed', 'start_time' => '21:00', 'end_time' => '05:00', 'crosses_midnight' => true, 'is_active' => true, 'department_id' => 90001, 'created_by' => $this->d2Admin->employee_id]);
        $shift->forceFill(['id' => 90001])->save();
        $pattern = new ShiftRotationPattern(['name' => 'Rota '.self::MARKER, 'code' => 'R-90001', 'cycle_length_days' => 2, 'definition' => [90001, null], 'is_active' => true, 'department_id' => 90001, 'created_by' => $this->d2Admin->employee_id]);
        $pattern->forceFill(['id' => 90001])->save();
        RosterDay::create(['user_id' => $this->c1->employee_id, 'date' => self::DAY, 'shift_id' => 90001, 'source' => 'manual', 'note' => self::MARKER]);

        $type = LeaveSetting::create(['type' => 'Annual', 'symbol' => 'AL', 'days' => 20, 'eligibility' => 0, 'carry_forward' => false, 'earned_leave' => false, 'is_earned' => false, 'is_paid' => true, 'requires_approval' => true, 'auto_approve' => false]);
        $leave = Leave::create(['user_id' => $this->c1->employee_id, 'leave_type' => $type->id, 'from_date' => '2026-06-10', 'to_date' => '2026-06-11', 'no_of_days' => 2, 'status' => 'Pending', 'reason' => 'Leave '.self::MARKER]);
        $leave->forceFill(['id' => 90001])->save();
        Leave::create(['user_id' => $this->e1->employee_id, 'leave_type' => $type->id, 'from_date' => '2026-06-12', 'to_date' => '2026-06-12', 'no_of_days' => 1, 'status' => 'Pending', 'reason' => 'Own dept leave']);

        $attendance = Attendance::factory()->for($this->c1)->create(['date' => self::DAY, 'punchin' => self::DAY.' 09:00:00', 'punchout' => self::DAY.' 17:00:00', 'punchin_location' => json_encode(['address' => self::MARKER.' gate'])]);
        DB::table('attendances')->where('id', $attendance->id)->update(['id' => 90001]);
        Attendance::factory()->for($this->e1)->create(['date' => self::DAY, 'punchin' => self::DAY.' 09:00:00', 'punchout' => self::DAY.' 17:00:00']);

        $overtime = OvertimeRequest::create(['user_id' => $this->c1->employee_id, 'date' => self::DAY, 'requested_minutes' => 60, 'reason' => 'OT '.self::MARKER, 'status' => 'pending']);
        DB::table('overtime_requests')->where('id', $overtime->id)->update(['id' => 90001]);

        $asset = Asset::create(['asset_code' => 'AST-90001', 'name' => 'Radio '.self::MARKER, 'category' => 'Radio', 'assignee_id' => $this->c1->employee_id, 'assigned_date' => self::DAY, 'status' => 'assigned', 'notes' => self::MARKER]);
        DB::table('assets')->where('id', $asset->id)->update(['id' => 90001]);

        auth()->setUser($this->hr);
        $onboarding = Onboarding::create(['employee_id' => $this->c2->employee_id, 'start_date' => self::DAY, 'expected_completion_date' => '2026-06-30', 'status' => 'in_progress', 'notes' => self::MARKER]);
        DB::table('onboardings')->where('id', $onboarding->id)->update(['id' => 90001]);
        $offboarding = Offboarding::create(['employee_id' => $this->c1->employee_id, 'initiation_date' => self::DAY, 'last_working_date' => '2026-07-30', 'status' => 'in_progress', 'reason' => 'Exit '.self::MARKER, 'notes' => self::MARKER]);
        DB::table('offboardings')->where('id', $offboarding->id)->update(['id' => 90001]);
        auth()->logout();

        $loan = PettyCashLoan::create(['user_id' => $this->c1->employee_id, 'fund_name' => 'Fund '.self::MARKER, 'loan_amount' => 5000, 'original_amount' => 5000, 'current_balance' => 5000, 'status' => 'active', 'loan_date' => self::DAY, 'notes' => self::MARKER]);
        DB::table('petty_cash_loans')->where('id', $loan->id)->update(['id' => 90001]);
        PettyCashTransaction::create(['petty_cash_loan_id' => 90001, 'type' => 'expense', 'category' => 'fuel', 'amount' => 100, 'description' => 'Fuel '.self::MARKER, 'transaction_date' => self::DAY]);

        $this->d2Ids = [
            'user' => '90001', 'user2' => '90002', 'department' => '90001', 'designation' => '90001', 'shift' => '90001',
            'leave' => '90001', 'attendance' => '90001', 'asset' => '90001', 'onboarding' => '90001', 'offboarding' => '90001', 'loan' => '90001', 'overtime' => '90001',
        ];
    }
}
