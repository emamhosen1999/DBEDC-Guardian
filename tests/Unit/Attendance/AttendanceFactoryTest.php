<?php

namespace Tests\Unit\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_complete_punch_pair(): void
    {
        $user = User::factory()->create();
        $a = Attendance::factory()->for($user)->create();

        $this->assertNotNull($a->punchin);
        $this->assertNotNull($a->punchout);
        $this->assertTrue($a->punchout->greaterThan($a->punchin));
    }

    public function test_open_state_has_no_punchout(): void
    {
        $a = Attendance::factory()->open()->create();
        $this->assertNull($a->punchout);
    }
}
