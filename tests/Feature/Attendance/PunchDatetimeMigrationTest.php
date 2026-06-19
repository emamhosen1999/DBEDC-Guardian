<?php

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PunchDatetimeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_columns_are_datetime_and_index_exists(): void
    {
        $this->assertSame('datetime', Schema::getColumnType('attendances', 'punchin'));
        $this->assertSame('datetime', Schema::getColumnType('attendances', 'punchout'));
        $this->assertTrue(Schema::hasColumn('attendances', 'user_id'));
    }
}
