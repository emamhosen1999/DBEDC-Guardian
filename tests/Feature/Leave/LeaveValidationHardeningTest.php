<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LeaveValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed the exists-rule targets so validation reaches the half-day rules.
        User::factory()->create(['id' => 1]);
        LeaveSetting::create(['type' => 'Casual', 'days' => 10]);
    }

    /** @test */
    public function half_day_must_be_a_single_date(): void
    {
        $svc = new LeaveValidationService;
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-04', // multi-date
            'leaveReason' => 'invalid half day',
            'isHalfDay' => true, 'halfDaySession' => 'first_half',
        ]);

        $validator = $svc->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('toDate', $validator->errors()->toArray());
    }

    /** @test */
    public function half_day_session_is_required_when_half_day(): void
    {
        $svc = new LeaveValidationService;
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'leaveReason' => 'missing session', 'isHalfDay' => true,
        ]);

        $validator = $svc->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('halfDaySession', $validator->errors()->toArray());
    }

    /** @test */
    public function half_day_session_required_rejects_empty_string(): void
    {
        $svc = new LeaveValidationService;
        $req = Request::create('/leaves', 'POST', [
            'user_id' => 1, 'leaveType' => 'Casual',
            'fromDate' => '2026-03-02', 'toDate' => '2026-03-02',
            'leaveReason' => 'empty session', 'isHalfDay' => true, 'halfDaySession' => '',
        ]);

        $validator = $svc->validateLeaveRequest($req);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('halfDaySession', $validator->errors()->toArray());
    }

    /** @test */
    public function canonical_status_only(): void
    {
        $rules = (new LeaveValidationService)->getLeaveValidationRules();
        $this->assertSame('nullable|in:pending,approved,rejected,cancelled', $rules['status']);
    }
}
