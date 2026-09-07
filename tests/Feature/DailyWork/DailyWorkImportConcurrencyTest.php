<?php

namespace Tests\Feature\DailyWork;

use App\Exceptions\StaleModelVersionException;
use App\Models\DailyWork;
use App\Models\User;
use App\Services\Project\DailyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DailyWorkImportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_import_denies_mutation_of_an_unrelated_record(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('daily-works.view', 'web'));
        $work = DailyWork::factory()->create(['number' => 'RFI-PRIVATE-11', 'rfi_response_status' => null]);
        $file = UploadedFile::fake()->createWithContent('responses.csv', "RFI Number,Status,Date\nRFI-PRIVATE-11,approved,2026-09-01\n");

        $this->actingAs($actor)->postJson(route('dailyWorks.bulkImportResponseStatus'), ['file' => $file])
            ->assertOk()
            ->assertJsonPath('failed.0.error', 'Permission denied');

        $this->assertNull($work->fresh()->rfi_response_status);
        $this->assertSame(0, $work->fresh()->lock_version);
    }

    public function test_response_import_increments_the_record_version(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('daily-works.view', 'web'));
        $work = DailyWork::factory()->forUsers($actor, $actor)->create(['number' => 'RFI-OWN-11']);
        $file = UploadedFile::fake()->createWithContent('responses.csv', "RFI Number,Status,Date\nRFI-OWN-11,approved,2026-09-01\n");

        $this->actingAs($actor)->postJson(route('dailyWorks.bulkImportResponseStatus'), ['file' => $file])
            ->assertOk();

        $this->assertSame('approved', $work->fresh()->rfi_response_status);
        $this->assertSame(1, $work->fresh()->lock_version);
    }

    public function test_import_service_rejects_a_version_changed_since_parsing(): void
    {
        $actor = User::factory()->create();
        $work = DailyWork::factory()->forUsers($actor, $actor)->create();
        $service = app(DailyWorkService::class);
        $service->updateResponseStatus($work, 'approved', '2026-09-01', $actor->getKey(), null, 0);

        try {
            $service->updateResponseStatus($work, 'rejected', '2026-09-02', $actor->getKey(), null, 0);
            $this->fail('A stale import must be rejected.');
        } catch (StaleModelVersionException) {
            $this->assertSame('approved', $work->fresh()->rfi_response_status);
            $this->assertSame(1, $work->fresh()->lock_version);
        }
    }
}
