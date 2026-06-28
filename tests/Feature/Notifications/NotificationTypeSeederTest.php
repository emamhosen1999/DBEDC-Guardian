<?php
// tests/Feature/Notifications/NotificationTypeSeederTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationType;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_leave_and_attendance_types_with_locked_database_channel(): void
    {
        $this->seed(NotificationTypeSeeder::class);

        $approved = NotificationType::where('key', 'leave.approved')->first();
        $this->assertNotNull($approved);
        $this->assertContains('database', $approved->default_channels);
        $this->assertContains('database', $approved->locked_channels);
        $this->assertTrue($approved->is_active);
        $this->assertSame('leave', $approved->category);

        $this->assertNotNull(NotificationType::where('key', 'attendance.missed_punch_in')->first());

        $this->assertSame(11, NotificationType::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $this->seed(NotificationTypeSeeder::class);

        $this->assertSame(11, NotificationType::count());
    }
}
