<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSettingPaidTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function is_paid_symbol_and_is_earned_are_mass_assignable(): void
    {
        $setting = LeaveSetting::create([
            'type' => 'Casual', 'symbol' => 'C', 'days' => 10,
            'is_earned' => true, 'is_paid' => false,
        ]);

        $this->assertSame('C', $setting->symbol);
        $this->assertTrue($setting->is_earned);
        $this->assertFalse($setting->is_paid);
    }

    /** @test */
    public function is_paid_defaults_to_true(): void
    {
        $setting = LeaveSetting::create(['type' => 'Annual', 'days' => 20]);
        $this->assertTrue($setting->fresh()->is_paid);
    }
}
