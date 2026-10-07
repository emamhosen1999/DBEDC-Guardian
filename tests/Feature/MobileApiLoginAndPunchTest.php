<?php

namespace Tests\Feature;

use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GrantsAttendancePermissions;
use Tests\TestCase;

class MobileApiLoginAndPunchTest extends TestCase
{
    use GrantsAttendancePermissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);

        AttendanceSetting::create([
            'office_start_time' => '09:00:00',
            'office_end_time' => '17:00:00',
            'weekend_days' => json_encode(['friday', 'saturday']),
            'late_mark_after' => 15,
            'max_working_hours' => 12,
            'punch_in_out_alert' => true,
        ]);
    }

    public function test_mobile_api_login_returns_token_and_user_resource(): void
    {
        $user = User::factory()->create([
            'email' => 'employee@test.com',
            'password' => bcrypt('Password123!'),
            'employee_id' => 'EMP-99001',
        ]);
        $user->assignRole('Employee');

        $deviceId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'employee@test.com',
            'password' => 'Password123!',
            'device_id' => $deviceId,
            'device_name' => 'Test Mobile Device',
            'device_type' => 'android',
            'device_signature' => [
                'platform' => 'android',
                'os_version' => '14.0',
                'model' => 'Pixel 8',
                'brand' => 'Google',
                'app_version' => '1.1.4',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'token',
                'user' => [
                    'id',
                    'employee_id',
                    'name',
                    'email',
                ],
                'device_secret',
            ],
        ]);

        $this->assertEquals('EMP-99001', $response->json('data.user.employee_id'));
    }

    public function test_mobile_api_punch_in_and_today_status(): void
    {
        $attendanceType = AttendanceType::updateOrCreate(['slug' => 'geo_polygon'], [
            'name' => 'Office Geofence',
            'is_active' => true,
            'config' => [
                'polygons' => [
                    [
                        'id' => 'office_polygon',
                        'is_active' => true,
                        'points' => [
                            ['lat' => 23.8000, 'lng' => 90.4000],
                            ['lat' => 23.8200, 'lng' => 90.4000],
                            ['lat' => 23.8200, 'lng' => 90.4200],
                            ['lat' => 23.8000, 'lng' => 90.4200],
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create([
            'email' => 'employee2@test.com',
            'password' => bcrypt('Password123!'),
            'employee_id' => 'EMP-99002',
            'attendance_type_id' => $attendanceType->id,
        ]);
        $user->assignRole('Employee');
        $this->grantPunch($user);

        $deviceId = (string) Str::uuid();

        // 1. Mobile Login
        $loginResp = $this->postJson('/api/v1/auth/login', [
            'email' => 'employee2@test.com',
            'password' => 'Password123!',
            'device_id' => $deviceId,
            'device_name' => 'Test Device 2',
            'device_type' => 'android',
            'device_signature' => [
                'platform' => 'android',
                'os_version' => '14.0',
                'model' => 'Samsung S24',
                'brand' => 'Samsung',
                'app_version' => '1.1.4',
            ],
        ]);

        $loginResp->assertStatus(200);
        $token = $loginResp->json('data.token');

        // 2. Fetch today status before punch
        $todayBefore = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Device-Id', $deviceId)
            ->getJson('/api/v1/attendance/today');

        $todayBefore->assertStatus(200);

        // 3. Perform Punch In
        $punchResp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Device-Id', $deviceId)
            ->postJson('/api/v1/attendance/punch', [
                'lat' => 23.8103,
                'lng' => 90.4125,
                'location' => 'Dhaka Bypass Toll Plaza',
                'photo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            ]);

        $punchResp->assertStatus(200);
        $this->assertTrue($punchResp->json('success'));

        // 4. Verify today status reflects the punch
        $todayAfter = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Device-Id', $deviceId)
            ->getJson('/api/v1/attendance/today');

        $todayAfter->assertStatus(200);
        $this->assertNotEmpty($todayAfter->json('data.punches'));
        $this->assertEquals(200, $punchResp->status());
    }
}
