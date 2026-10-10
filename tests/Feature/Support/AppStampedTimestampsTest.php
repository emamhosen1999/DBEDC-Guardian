<?php

namespace Tests\Feature\Support;

use App\Models\HRM\RosterDayChange;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * request_logs and roster_day_changes used the column's CURRENT_TIMESTAMP default, which on production is the database
 * server's zone (US Eastern), 10-11 hours behind the application. They are now stamped by the application.
 */
class AppStampedTimestampsTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_logs_are_stamped_in_the_application_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 19:15:00', config('app.timezone')));
        $log = RequestLog::create(['ip_address' => '127.0.0.1', 'method' => 'GET', 'url' => 'https://example.test/attendance', 'response_status' => 200, 'duration_ms' => 12]);

        $this->assertSame('2026-10-10 19:15:00', $log->fresh()->created_at->format('Y-m-d H:i:s'));
    }

    public function test_roster_changes_are_stamped_in_the_application_zone(): void
    {
        if (! Schema::hasTable('roster_day_changes')) {
            $this->markTestSkipped('roster_day_changes is not migrated in this schema');
        }
        Carbon::setTestNow(Carbon::parse('2026-10-10 07:05:00', config('app.timezone')));
        $change = RosterDayChange::create(['roster_day_id' => 1, 'actor_id' => 'system', 'field' => 'shift_id', 'old_value' => '1', 'new_value' => '2', 'reason' => 'test']);

        $this->assertSame('2026-10-10 07:05:00', RosterDayChange::query()->find($change->id)->created_at->format('Y-m-d H:i:s'));
    }
}
