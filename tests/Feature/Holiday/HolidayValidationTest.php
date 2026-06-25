<?php

namespace Tests\Feature\Holiday;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HolidayValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsHolidayManager(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('holidays.create');
        $user->givePermissionTo('holidays.create');
        $this->actingAs($user);

        return $user;
    }

    /** @test */
    public function recurrence_pattern_rejects_unsupported_values(): void
    {
        $this->actingAsHolidayManager();

        $res = $this->postJson(route('holiday-add'), [
            'title' => 'Bad', 'fromDate' => '2026-07-01', 'toDate' => '2026-07-01',
            'type' => 'public', 'is_recurring' => true, 'recurrence_pattern' => 'nth_weekday',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['recurrence_pattern']);
    }

    /** @test */
    public function recurrence_pattern_none_is_accepted(): void
    {
        $this->actingAsHolidayManager();

        $res = $this->postJson(route('holiday-add'), [
            'title' => 'One-off', 'fromDate' => '2026-08-01', 'toDate' => '2026-08-01',
            'type' => 'public', 'is_recurring' => false, 'recurrence_pattern' => 'none',
        ]);

        $res->assertStatus(200);
        // is_recurring=false → recurrence_pattern stored as null (coherent).
        $this->assertDatabaseHas('holidays', ['title' => 'One-off', 'is_recurring' => false, 'recurrence_pattern' => null]);
    }
}
