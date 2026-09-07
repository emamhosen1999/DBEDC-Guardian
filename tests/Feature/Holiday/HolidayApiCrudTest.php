<?php

namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HolidayApiCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_api_exposes_complete_holiday_crud_with_separate_permissions(): void
    {
        $manager = User::factory()->create();
        foreach (['holidays.view', 'holidays.create', 'holidays.update', 'holidays.delete'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $create = $this->actingAs($manager)->postJson(route('api.holidays.store'), [
            'title' => 'Guardian Day',
            'description' => 'Initial description',
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-10',
            'type' => 'company',
        ])->assertOk();

        $holiday = Holiday::findOrFail($create->json('holidays.0.id'));

        $this->actingAs($manager)->getJson(route('api.holidays'))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Guardian Day']);
        $this->actingAs($manager)->getJson(route('holidays.stats'))
            ->assertOk()
            ->assertJsonPath('stats.total_holidays', 1);
        $this->actingAs($manager)->getJson(route('api.holidays.show', $holiday))
            ->assertOk()
            ->assertJsonPath('holiday.id', $holiday->id);

        $this->actingAs($manager)->putJson(route('api.holidays.update', $holiday), [
            'title' => 'Guardian Day Updated',
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-11',
            'type' => 'company',
        ])->assertOk();

        $this->assertDatabaseHas('holidays', [
            'id' => $holiday->id,
            'title' => 'Guardian Day Updated',
            'to_date' => '2026-12-11 00:00:00',
        ]);

        $this->actingAs($manager)->deleteJson(route('api.holidays.destroy', $holiday))->assertOk();
        $this->assertSoftDeleted($holiday);
    }

    public function test_create_permission_cannot_be_used_to_update_a_holiday(): void
    {
        $creator = User::factory()->create();
        $creator->givePermissionTo(Permission::findOrCreate('holidays.create', 'web'));
        $holiday = Holiday::create([
            'title' => 'Protected holiday',
            'from_date' => '2026-11-01',
            'to_date' => '2026-11-01',
            'type' => 'company',
            'is_active' => true,
        ]);

        $this->actingAs($creator)->putJson(route('holiday-update', $holiday), [
            'title' => 'Unauthorized edit',
            'fromDate' => '2026-11-01',
            'toDate' => '2026-11-01',
            'type' => 'company',
        ])->assertForbidden();

        foreach (['holiday-add', 'holidays-add', 'api.holidays.store'] as $routeName) {
            $this->actingAs($creator)->postJson(route($routeName), [
                'id' => $holiday->id,
                'title' => 'Unauthorized legacy edit',
                'fromDate' => '2026-11-01',
                'toDate' => '2026-11-01',
                'type' => 'company',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('holidays', [
            'id' => $holiday->id,
            'title' => 'Protected holiday',
        ]);
    }

    public function test_update_only_editor_preserves_original_creator(): void
    {
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        $editor->givePermissionTo(Permission::findOrCreate('holidays.update', 'web'));
        $holiday = Holiday::create([
            'title' => 'Original title',
            'from_date' => '2026-11-01',
            'to_date' => '2026-11-01',
            'type' => 'company',
            'created_by' => $creator->getKey(),
        ]);

        $this->actingAs($editor)->putJson(route('api.holidays.update', $holiday), [
            'title' => 'Edited title',
            'from_date' => '2026-11-01',
            'to_date' => '2026-11-01',
            'type' => 'company',
        ])->assertOk();

        $this->assertSame((string) $creator->getKey(), (string) $holiday->fresh()->created_by);
        $this->assertSame((string) $editor->getKey(), (string) $holiday->fresh()->updated_by);
    }
}
