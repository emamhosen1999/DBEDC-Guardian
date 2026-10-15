<?php

namespace Tests\Feature\DailyWork;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Jurisdiction\JurisdictionTestCase;

/** Fixtures for the Daily Works overhaul: the three phases, every daily-works permission, and viewers with chosen permissions. */
abstract class DailyWorkTestCase extends JurisdictionTestCase
{
    protected const DW = [
        'view', 'create', 'update', 'delete', 'import', 'export', 'status.update', 'status.reopen', 'inspection.update', 'assign', 'incharge.update',
        'files.manage', 'bulk', 'restore', 'import.reverse', 'history.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::DW as $p) {
            Permission::findOrCreate('daily-works.'.$p, 'web');
        }
    }

    /** @param array<int, string> $names short names, e.g. ['view', 'status.update'] */
    protected function viewer(array $names, array $attrs = []): User
    {
        $u = $this->employee([], $attrs);
        $u->givePermissionTo(array_map(fn (string $n): string => 'daily-works.'.$n, $names));

        return $u;
    }

    /** A Super-Administrator-like viewer: every daily-works permission and the Administrator role (sees everything). */
    protected function dwAdmin(): User
    {
        $u = $this->viewer(self::DW);
        Role::findOrCreate('Administrator', 'web');
        $u->assignRole('Administrator');

        return $u;
    }
}
