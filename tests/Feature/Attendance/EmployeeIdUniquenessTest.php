<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeIdUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_employee_id_has_unique_index(): void
    {
        $indexes = collect(Schema::getIndexes('users'));
        $hasUnique = $indexes->contains(fn ($i) => $i['unique'] && in_array('employee_id', $i['columns'], true));
        $this->assertTrue($hasUnique, 'users.employee_id must have a unique index');
    }
}
