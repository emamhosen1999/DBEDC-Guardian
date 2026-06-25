<?php

namespace Tests\Feature\Leave\Ledger;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeaveSettingPolicyApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsManager(): User
    {
        Permission::findOrCreate('leave-settings.update');
        $user = User::factory()->create();
        $user->givePermissionTo('leave-settings.update');
        $this->actingAs($user);

        return $user;
    }

    /** @test */
    public function storing_a_leave_type_persists_the_accrual_policy(): void
    {
        $this->actingAsManager();

        $this->postJson(route('add-leave-type'), [
            'type' => 'Earned', 'days' => 12, 'carry_forward' => true, 'earned_leave' => true,
            'accrual_method' => 'monthly', 'accrual_rate' => 12, 'probation_months' => 3,
            'prorate_on_join' => true, 'carry_forward_cap' => 10, 'carry_expiry_months' => 12,
            'is_encashable' => true, 'allow_negative' => false,
        ])->assertCreated();

        $s = LeaveSetting::where('type', 'Earned')->first();
        $this->assertSame('monthly', $s->accrual_method);
        $this->assertSame(12.0, (float) $s->accrual_rate);
        $this->assertSame(3, $s->probation_months);
        $this->assertSame(10.0, (float) $s->carry_forward_cap);
        $this->assertTrue($s->is_encashable);
    }

    /** @test */
    public function updating_a_leave_type_persists_the_accrual_policy(): void
    {
        $this->actingAsManager();
        $s = LeaveSetting::create(['type' => 'Casual', 'days' => 10]);

        $this->putJson(route('update-leave-type', ['id' => $s->id]), [
            'type' => 'Casual', 'days' => 10, 'carry_forward' => false, 'earned_leave' => false,
            'accrual_method' => 'annual_upfront', 'accrual_rate' => 10, 'allow_negative' => true,
        ])->assertOk();

        $s->refresh();
        $this->assertSame('annual_upfront', $s->accrual_method);
        $this->assertTrue($s->allow_negative);
    }
}
