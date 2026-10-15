<?php

namespace Tests\Feature\Attendance;

use App\Jobs\ExportAttendanceReport;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Support\Attendance\RegisterAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A scoped requester's register export (file AND JSON response) carries nothing of an out-of-scope department:
 * no sheet, page, code, name, employee or filename part, not even an empty one.
 */
class AttendanceRegisterScopeTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = 'CANARY-D2-7f3a';

    private string $avatarDir;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Department Manager']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
        AttendanceSetting::create(['office_start_time' => '09:00:00', 'late_mark_after' => 15, 'weekend_days' => ['friday']]);
        $this->avatarDir = sys_get_temp_dir().'/register-scope-'.uniqid();
        $this->app->bind(RegisterAvatar::class, fn () => new RegisterAvatar($this->avatarDir));
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->avatarDir);
        parent::tearDown();
    }

    /** @return array{0: User, 1: int, 2: int} the D1 manager, D1 id, D2 id */
    private function world(): array
    {
        $now = ['created_at' => now(), 'updated_at' => now(), 'is_active' => true];
        $d1 = DB::table('departments')->insertGetId(['name' => 'Alpha Works', 'code' => 'ALP'] + $now);
        $d2 = DB::table('departments')->insertGetId(['name' => self::CANARY.' Dept', 'code' => self::CANARY] + $now);
        $manager = User::factory()->create(['name' => 'Alpha Manager', 'department_id' => $d1]);
        $manager->assignRole(['Employee', 'Department Manager']);
        $manager->givePermissionTo('attendance.view');
        $mate = User::factory()->create(['name' => 'Alpha Mate', 'department_id' => $d1]);
        $mate->assignRole('Employee');
        $other = User::factory()->create(['name' => self::CANARY.' Person', 'department_id' => $d2]);
        $other->assignRole('Employee');
        Attendance::create(['user_id' => $other->id, 'date' => now()->toDateString(), 'punchin' => now()->setTime(9, 0), 'punchout' => now()->setTime(17, 0)]);

        return [$manager, $d1, $d2];
    }

    public function test_a_scoped_managers_export_response_and_file_hold_nothing_of_another_department(): void
    {
        [$manager, , $d2] = $this->world();

        foreach (['attendance.monthlyCalendar.export', 'attendance.exportAdminExcel', 'attendance.exportAdminPdf'] as $route) {
            // The hostile probe: ask for the other department by id.
            $response = $this->actingAs($manager)->getJson(route($route, ['department_id' => $d2, 'from' => '2026-09-01', 'to' => '2026-09-30', 'type' => 'excel']));
            $response->assertOk();
            $body = $response->getContent();
            $this->assertStringNotContainsStringIgnoringCase(self::CANARY, $body, "$route: JSON response");
            $this->assertStringNotContainsString('ALP_', $body, 'a department filter that is not the whole scope still names no code');
            $this->assertMatchesRegularExpression('/Attendance_Register_2026-09-01_2026-09-30_/', $body, 'a neutral filename');
            $this->assertStringNotContainsString('"filename":"_', $body, 'a neutral filename never starts with an underscore');
        }

        // The generated file: run the job for the same hostile request.
        ExportAttendanceReport::dispatchSync('register_excel', null, null, (string) $manager->id, 'scope.xlsx', ['from' => '2026-09-01', 'to' => '2026-09-30', 'department_id' => $d2]);
        $book = IOFactory::load(Storage::disk('public')->path('exports/scope.xlsx'));
        $this->assertSame(['ALP', 'Summary'], $book->getSheetNames(), 'only the requester\'s own department, then the Summary');
        $text = '';
        foreach ($book->getAllSheets() as $ws) {
            foreach ($ws->toArray(null, false, false) as $row) {
                $text .= ' '.implode(' ', array_map('strval', $row));
            }
        }
        $this->assertStringNotContainsStringIgnoringCase(self::CANARY, $text, 'no D2 department, employee, code or name in the workbook');
        $this->assertStringContainsString('Alpha Mate', $text);

        ExportAttendanceReport::dispatchSync('register_pdf', null, null, (string) $manager->id, 'scope.pdf', ['from' => '2026-09-01', 'to' => '2026-09-30', 'department_id' => $d2]);
        $this->assertStringNotContainsStringIgnoringCase(self::CANARY, Storage::disk('public')->get('exports/scope.pdf'));
    }

    public function test_a_visible_department_still_names_its_own_file(): void
    {
        [$manager, $d1] = $this->world();
        $body = $this->actingAs($manager)->getJson(route('attendance.monthlyCalendar.export', ['department_id' => $d1, 'from' => '2026-09-01', 'to' => '2026-09-30', 'type' => 'excel']))->assertOk()->getContent();
        $this->assertStringContainsString('ALP_Attendance_Register_2026-09-01_2026-09-30_', $body);
    }
}
