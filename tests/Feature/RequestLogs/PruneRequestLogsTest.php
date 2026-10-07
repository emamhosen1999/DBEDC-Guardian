<?php

namespace Tests\Feature\RequestLogs;

use App\Models\RequestLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneRequestLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(Storage::disk('local')->path('request-log-archives'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(Storage::disk('local')->path('request-log-archives'));
        parent::tearDown();
    }

    private function seedLogs(int $old, int $fresh): void
    {
        $row = fn (string $when, int $i) => [
            'method' => 'GET', 'url' => "https://x.test/p/{$i}", 'response_status' => 200,
            'headers' => json_encode(['a' => 'b']), 'created_at' => $when,
        ];
        DB::table('request_logs')->insert(array_map(fn ($i) => $row(now()->subDays(100)->toDateTimeString(), $i), range(1, $old)));
        DB::table('request_logs')->insert(array_map(fn ($i) => $row(now()->subDays(5)->toDateTimeString(), $i), range(1, $fresh)));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->seedLogs(7, 3);

        $this->artisan('request-logs:prune', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('Found 7 request log(s)')
            ->assertSuccessful();

        $this->assertSame(10, RequestLog::count());
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('request-log-archives'));
    }

    public function test_deletes_only_stale_rows_in_chunks(): void
    {
        $this->seedLogs(25, 4);

        $this->artisan('request-logs:prune', ['--days' => 90, '--chunk' => 100])
            ->expectsOutputToContain('Deleted 25 request log(s)')
            ->assertSuccessful();

        $this->assertSame(4, RequestLog::count());
        $this->assertSame(0, RequestLog::where('created_at', '<', now()->subDays(90))->count());
    }

    public function test_chunk_boundary_deletes_everything_when_count_is_a_multiple_of_chunk(): void
    {
        $this->seedLogs(200, 1);

        $this->artisan('request-logs:prune', ['--days' => 90, '--chunk' => 100])->assertSuccessful();

        $this->assertSame(1, RequestLog::count());
    }

    public function test_archive_writes_gzip_ndjson_before_deleting(): void
    {
        $this->seedLogs(12, 2);

        $this->artisan('request-logs:prune', ['--days' => 90, '--archive' => true, '--chunk' => 100])
            ->expectsOutputToContain('Archived 12 request log(s)')
            ->expectsOutputToContain('Deleted 12 request log(s)')
            ->assertSuccessful();

        $files = glob(Storage::disk('local')->path('request-log-archives').'/*.ndjson.gz');
        $this->assertCount(1, $files);

        $lines = array_values(array_filter(explode("\n", gzdecode(file_get_contents($files[0])))));
        $this->assertCount(12, $lines);
        $first = json_decode($lines[0], true);
        $this->assertSame('GET', $first['method']);
        $this->assertArrayHasKey('created_at', $first);
        $this->assertSame(2, RequestLog::count());
    }

    public function test_days_defaults_to_config_retention(): void
    {
        config(['request-logs.retention_days' => 3]);
        $this->seedLogs(2, 2); // fresh rows are 5 days old, so they are stale under a 3 day window too

        $this->artisan('request-logs:prune')->assertSuccessful();

        $this->assertSame(0, RequestLog::count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'request-logs:prune'));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
    }
}
