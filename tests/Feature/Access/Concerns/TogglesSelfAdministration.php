<?php

namespace Tests\Feature\Access\Concerns;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\SelfAdministration;
use Spatie\Permission\PermissionRegistrar;

/**
 * The world's Department Admin holds the per-person `access.self-administration` exception (as Mahdi
 * does in production). Tests that prove the plain segregation-of-duties rule ("never on himself")
 * revoke it first.
 */
trait TogglesSelfAdministration
{
    protected function withoutSelfAdministration(): void
    {
        // Through the same instance the test acts as, so its loaded permissions are dropped too.
        if (isset($this->admin) && $this->admin->hasDirectPermission(SelfAdministration::PERMISSION)) {
            $this->admin->revokePermissionTo(SelfAdministration::PERMISSION);
        }
        foreach (User::permission(SelfAdministration::PERMISSION)->get() as $holder) {
            $holder->revokePermissionTo(SelfAdministration::PERMISSION);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DepartmentScope::class)->forget();
    }
}
