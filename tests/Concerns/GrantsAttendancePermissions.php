<?php

namespace Tests\Concerns;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The mobile attendance endpoints enforce the same permissions as their web routes
 * (attendance.own.punch to punch; attendance.correct|create|update to mark present).
 */
trait GrantsAttendancePermissions
{
    protected function grantPermissions(User $user, string ...$names): User
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function grantPunch(User $user): User
    {
        return $this->grantPermissions($user, 'attendance.own.punch');
    }
}
