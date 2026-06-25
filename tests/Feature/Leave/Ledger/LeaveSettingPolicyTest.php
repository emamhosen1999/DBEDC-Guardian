<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveSettingPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function policy_columns_are_mass_assignable_with_defaults(): void
    {
        $s = LeaveSetting::create([
            'type' => 'Earned', 'days' => 12,
            'accrual_method' => 'monthly', 'accrual_rate' => 12,
            'probation_months' => 3, 'carry_forward_cap' => 10, 'carry_expiry_months' => 12,
            'is_encashable' => true, 'allow_negative' => false,
        ])->fresh();

        $this->assertSame('monthly', $s->accrual_method);
        $this->assertSame(12.0, (float) $s->accrual_rate);
        $this->assertSame(3, $s->probation_months);
        $this->assertTrue($s->prorate_on_join);     // default true
        $this->assertSame(10.0, (float) $s->carry_forward_cap);
        $this->assertTrue($s->is_encashable);
        $this->assertFalse($s->allow_negative);
    }

    /** @test */
    public function defaults_apply_when_unspecified(): void
    {
        $s = LeaveSetting::create(['type' => 'Casual', 'days' => 10])->fresh();
        $this->assertSame('annual_upfront', $s->accrual_method);
        $this->assertNull($s->carry_forward_cap);
        $this->assertFalse($s->is_encashable);
        $this->assertFalse($s->allow_negative);
    }
}
