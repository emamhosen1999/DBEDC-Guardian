<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAuditLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_before_after_as_arrays(): void
    {
        $actor = User::factory()->create();
        $log = AttendanceAuditLog::create([
            'actor_id' => $actor->id,
            'attendance_id' => 99,
            'action' => 'update',
            'before' => ['punchin' => '09:00'],
            'after' => ['punchin' => '09:15'],
            'reason' => 'correction',
            'ip' => '127.0.0.1',
        ]);

        $fresh = $log->fresh();
        $this->assertSame(['punchin' => '09:00'], $fresh->before);
        $this->assertSame('update', $fresh->action);
        $this->assertSame($actor->id, $fresh->actor->id);
    }
}
