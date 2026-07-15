<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Anti-falsification guards on the punch pipeline:
 *   1. A field method that structurally requires a capture photo (geo-polygon /
 *      route-waypoint) must reject a photo-less punch (422), while non-photo
 *      methods (wifi/IP, QR) keep working with no photo.
 *   2. GPS is not trusted blindly: an out-of-polygon punch is rejected, and an
 *      implausibly coarse client-reported accuracy is rejected — but a punch
 *      that omits accuracy (older client) is never rejected for that reason.
 */
class PunchIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A geo-polygon type with one square boundary around (23.81, 90.41).
     */
    private function geoPolygonType(): AttendanceType
    {
        // Slug MUST carry a numeric suffix: AttendanceValidatorFactory and the
        // photo guard reduce the base slug via preg_replace('/_\d+$/', ...), so a
        // non-numeric suffix (e.g. uniqid()) would never resolve to geo_polygon.
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
     * Glide/GD conversion fail ("Could not load image"), which the punch flow
     * surfaces as a 500 unrelated to the behaviour under test.
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

    /** (a) A photo-required type rejects a photo-less punch. */
    public function test_photo_required_type_rejects_punch_without_photo(): void
    {
        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'lat' => 23.81,
            'lng' => 90.41,
            // no photo
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonFragment(['message' => 'A verification photo is required for this attendance method. Please capture a photo and try again.']);

        $this->assertDatabaseCount('attendances', 0);
    }

    /** (a+) The same photo-required type accepts the punch once a photo is supplied. */
    public function test_photo_required_type_accepts_punch_with_photo(): void
    {
        Storage::fake('public');

        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'lat' => 23.81,
            'lng' => 90.41,
            'photo' => $this->samplePhoto(),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'punch_in');

        $this->assertDatabaseCount('attendances', 1);
    }

    /** (b) A non-photo type still succeeds without a photo. */
    public function test_non_photo_type_succeeds_without_photo(): void
    {
        $type = AttendanceType::factory()->wifiIp()->create([
            'is_active' => true,
            'config' => [
                'ip_locations' => [],
                'validation_mode' => 'any',
                'allow_without_network' => true,
            ],
        ]);
        $user = $this->userWithType($type);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', []);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('action', 'punch_in');

        $this->assertDatabaseCount('attendances', 1);
    }

    /** (c) An out-of-polygon punch is rejected. */
    public function test_out_of_polygon_punch_is_rejected(): void
    {
        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'lat' => 24.90,   // far outside the Site A boundary
            'lng' => 91.90,
            'photo' => $this->samplePhoto(),
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonFragment(['message' => 'You are not within any allowed location boundary.']);

        $this->assertDatabaseCount('attendances', 0);
    }

    /** (d) A punch reporting an implausibly coarse GPS accuracy is rejected. */
    public function test_coarse_gps_accuracy_is_rejected(): void
    {
        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'lat' => 23.81,
            'lng' => 90.41,
            'accuracy' => 5000, // far worse than the 1000m default ceiling
            'photo' => $this->samplePhoto(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('attendances', 0);
    }

    /** (d+) A punch that omits accuracy (older client) is NOT rejected for accuracy. */
    public function test_punch_without_accuracy_field_is_not_rejected(): void
    {
        Storage::fake('public');

        $user = $this->userWithType($this->geoPolygonType());
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'lat' => 23.81,
            'lng' => 90.41,
            'photo' => $this->samplePhoto(),
            // no accuracy field
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
