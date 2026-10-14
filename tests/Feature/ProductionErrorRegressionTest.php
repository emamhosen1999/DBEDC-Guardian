<?php

namespace Tests\Feature;

use App\Jobs\ExportPettyCashTransactions;
use App\Models\HRM\Attendance;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\User;
use App\Services\Attendance\AttendanceQueryService;
use App\Services\PettyCash\PettyCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Errors seen in the production log between 2026-10-10 and 2026-10-14, each reproduced so it cannot return.
 */
class ProductionErrorRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ncr_register_lists_assignees_by_employee_id(): void
    {
        // Was: select `id`, `name` from `users` -> "Unknown column 'id'" on every visit to the NCR register.
        $this->withoutVite();
        Permission::findOrCreate('quality.ncr.view', 'web');
        $viewer = User::factory()->create(['name' => 'Aaron Viewer']);
        $viewer->givePermissionTo('quality.ncr.view');

        $this->actingAs($viewer)->get(route('quality.ncr.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Quality/NcrRegister')
                ->where('options.users.0.id', (string) $viewer->employee_id)
                ->where('options.users.0.name', 'Aaron Viewer'));
    }

    public function test_the_petty_cash_export_job_writes_the_ledger(): void
    {
        // Was: getTransactionHistory($loan, 1, 10000) -> TypeError, the export never produced a file.
        Storage::fake('public');
        $user = User::factory()->create();
        $loan = PettyCashLoan::create(['user_id' => $user->employee_id, 'fund_name' => 'Site', 'loan_amount' => 1000, 'original_amount' => 1000, 'current_balance' => 900, 'status' => 'active', 'loan_date' => now()->toDateString()]);
        PettyCashTransaction::create(['petty_cash_loan_id' => $loan->id, 'type' => 'expense', 'category' => 'fuel', 'amount' => 100, 'description' => 'Fuel', 'transaction_date' => now()->toDateString()]);

        (new ExportPettyCashTransactions($loan->id, (string) $user->employee_id, 'ledger.csv'))->handle(app(PettyCashService::class));

        Storage::disk('public')->assertExists('exports/ledger.csv');
        $this->assertStringContainsString('Fuel', Storage::disk('public')->get('exports/ledger.csv'));
    }

    public function test_present_employees_skip_an_attendance_whose_employee_was_removed(): void
    {
        // Was: "Attempt to read property 'id' on null" -> the whole present list failed for everyone.
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        $kept = User::factory()->create(['name' => 'Kept Employee']);
        $removed = User::factory()->create(['name' => 'Removed Employee']);
        foreach ([$kept, $removed] as $user) {
            Attendance::create(['user_id' => $user->employee_id, 'date' => '2026-10-12', 'punchin' => '2026-10-12 09:00:00']);
        }
        $removed->delete(); // soft delete: the attendance row stays, its employee no longer resolves

        $present = app(AttendanceQueryService::class)->getPresentUsersForDate('2026-10-12');

        $this->assertSame(['Kept Employee'], array_column(array_column($present, 'user'), 'name'));
    }
}
