<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlySummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_view_user_can_download_summary(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.view');

        $res = $this->actingAs($admin)->get('/attendance/monthly-summary/export?month=2026-06');

        $res->assertOk();
        $res->assertHeader('content-disposition');
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('content-type')
        );
    }

    public function test_pdf_variant_can_be_downloaded(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.view');

        $res = $this->actingAs($admin)->get('/attendance/monthly-summary/export?month=2026-06&type=pdf');

        $res->assertOk();
        $res->assertHeader('content-disposition');
        $this->assertStringContainsString(
            'pdf',
            strtolower($res->headers->get('content-type'))
        );
    }

    public function test_unauthorized_user_blocked(): void
    {
        $user = User::factory()->create();
        $res = $this->actingAs($user)->get('/attendance/monthly-summary/export?month=2026-06');
        $res->assertForbidden();
    }
}

