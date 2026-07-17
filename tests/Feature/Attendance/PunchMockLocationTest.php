<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mock-location (fake GPS) rejection on the punch pipeline.
 *
 * Android exposes whether a fix came from a mock provider (expo-location
 * LocationObject.mocked); the app forwards it as the `is_mocked` punch field.
 * The guard must reject an EXPLICITLY mocked fix while degrading safely:
 *   - is_mocked = true  -> rejected (422) when attendance.reject_mock_location is on
 *   - is_mocked = false -> accepted (client reports a genuine fix)
 *   - field omitted     -> accepted (older client / iOS cannot report it)
 *   - flag off          -> accepted even when mocked (staged rollout / observe)
 */
class PunchMockLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A geo-polygon type with one square boundary around (23.81, 90.41).
     *
     * Slug MUST carry a numeric suffix: AttendanceValidatorFactory and the photo
     * guard reduce the base slug via preg_replace('/_\d+$/', ...), so a non-numeric
     * suffix would never resolve to geo_polygon.
     */
    private function geoPolygonType(): AttendanceType
    {
        return AttendanceType::factory()->create([
            'name' => 'Geo Polygon Site',
            'slug' => 'geo_polygon_1',
            'is_active' => true,
            'config' => [
                'polygons' => [
                    [
                        'id' => 'poly_1',
                        'name' => 'Site A',
                        'is_active' => true,
                        'points' => [
                            ['lat' => 23.80, 'lng' => 90.40],
                            ['lat' => 23.80, 'lng' => 90.42],
                            ['lat' => 23.82, 'lng' => 90.42],
                            ['lat' => 23.82, 'lng' => 90.40],
                        ],
                    ],
                ],
                'validation_mode' => 'any',
                'allow_without_location' => false,
            ],
        ]);
    }

    private function userWithType(AttendanceType $type): User
    {
        return User::factory()->create([
            'attendance_type_id' => $type->id,
        ]);
    }

    /**
     * A real, loadable base64 PNG data URL. It must be a genuine raster the media
     * library's thumbnail conversion can open — a degenerate 1x1 fixture makes the
     * GD conversion fail ("Could not load image"), surfacing as a 500 unrelated to
     * the behaviour under test.
     */
    private function samplePhoto(): string
    {
        $image = imagecreatetruecolor(16, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 120, 160));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * An in-boundary punch payload; caller merges the is_mocked variant under test.
     */
    private function punch(array $overrides = []): array
    {
        return array_merge([
            'lat' => 23.81,
            'lng' => 90.41,
            'photo' => $this->samplePhoto(),
        ], $overrides);
    }

    /** (a) An explicitly mocked fix is rejected while the flag is on. */
    public function test_mocked_location_punch_is_rejected(): void
    {
        config(['attendance.reject_mock_location' => true]);

        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', $this->punch([
            'is_mocked' => true,
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('attendances', 0);
    }

    /** (b) A client reporting a genuine (non-mocked) fix still succeeds. */
    public function test_non_mocked_location_punch_succeeds(): void
    {
        Storage::fake('public');
        config(['attendance.reject_mock_location' => true]);

        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', $this->punch([
            'is_mocked' => false,
        ]));

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'punch_in');

        $this->assertDatabaseCount('attendances', 1);
    }

    /** (c) A punch that OMITS the flag (older client / iOS) is never rejected for it. */
    public function test_punch_without_is_mocked_field_is_not_rejected(): void
    {
        Storage::fake('public');
        config(['attendance.reject_mock_location' => true]);

        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', $this->punch());

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'punch_in');

        $this->assertDatabaseCount('attendances', 1);
    }

    /** (d) With the flag staged off, even a mocked fix is accepted (observe mode). */
    public function test_mocked_location_is_accepted_when_flag_is_disabled(): void
    {
        Storage::fake('public');
        config(['attendance.reject_mock_location' => false]);

        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', $this->punch([
            'is_mocked' => true,
        ]));

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('attendances', 1);
    }
}
