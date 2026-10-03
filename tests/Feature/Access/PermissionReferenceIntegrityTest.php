<?php

namespace Tests\Feature\Access;

use App\Services\Access\RoleCatalog;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Database\Seeders\EventPermissionsSeeder;
use Database\Seeders\ModulePermissionSeeder;
use Database\Seeders\OmRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * No PHANTOM permission: every permission a route middleware names, and every permission literal handed to
 * can() / hasPermissionTo() / hasAnyPermission() / checkPermissionTo() / hasAllPermissions() in app/, must
 * exist in the catalog. A phantom is a permission nobody can ever hold: `can()` quietly answers false (only the
 * Super Administrator, through Gate::before, gets in) and `hasPermissionTo()` throws PermissionDoesNotExist (HTTP 500).
 * Section 2.2 of docs/audit/ROLE_CATALOG_2026-10-03.md lists the known ones.
 *
 * The catalog is whatever the seeders and migrations create here, plus the permissions the catalog adds. The ONLY
 * exceptions are the known phantoms below, each with the Phase B fix that removes it and a review date. The list may
 * only shrink: an entry that stopped being a phantom, or stopped being referenced, fails the test until it is deleted.
 */
class PermissionReferenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** Dotted, lowercase: the shape of a permission name (a policy ability such as `update` has no dot). */
    private const PERMISSION_SHAPE = '/^[a-z][a-z0-9-]*(\.[a-z0-9-]+)+$/';

    /**
     * Known phantoms: permission => Phase B fix (docs/audit/ROLE_CATALOG_2026-10-03.md, section 2.2). Review by 2026-12-31.
     *
     * @var array<string, string>
     */
    private const KNOWN_PHANTOMS = [
        'daily-works.own.view' => 'B: use daily-works.view (DashboardController, CommandCenterService, widgets, mobile menuAccess)',
        'hr.safety.incidents.delete' => 'create with the safety module, or drop the policy reference',
        'hr.safety.inspections.delete' => 'create with the safety module, or drop the policy reference',
        'hr.safety.training.delete' => 'create with the safety module, or drop the policy reference',
        'hr.training.manage' => 'B: TrainingMaterial uses hasPermissionTo(); use can()',
        'permissions.view' => 'B: PermissionController gates on permissions.assign',
        'permissions.create' => 'B: PermissionController gates on permissions.assign',
        'permissions.update' => 'B: PermissionController gates on permissions.assign',
        'permissions.delete' => 'B: PermissionController gates on permissions.assign',
    ];

    /** @var array<int, string> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([ComprehensiveRolePermissionSeeder::class, OmRbacSeeder::class, EventPermissionsSeeder::class, ModulePermissionSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
        $this->catalog = array_values(array_unique(array_merge(Permission::pluck('name')->all(), array_keys(RoleCatalog::NEW_PERMISSIONS))));
    }

    /** @return array<string, array<int, string>> permission => where it is referenced */
    private function routeReferences(): array
    {
        $references = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^(?:permission|role_or_permission|custom_permission):(.+)$/', $middleware, $match)) {
                    foreach (preg_split('/[|,]/', $match[1]) as $name) {
                        $name = trim($name);
                        // role_or_permission mixes role names ("Manager") and permissions: only the dotted ones are permissions
                        if (preg_match(self::PERMISSION_SHAPE, $name)) {
                            $references[$name][] = 'route '.($route->getName() ?? $route->uri());
                        }
                    }
                }
            }
        }

        return $references;
    }

    /** @return array<string, array<int, string>> permission => where it is referenced */
    private function codeReferences(): array
    {
        $references = [];
        $call = '/(?:->|::)(?:can|cannot|hasPermissionTo|hasAnyPermission|hasAllPermissions|hasDirectPermission|checkPermissionTo|canAny)\(\s*(\[[^\]]*\]|\'[^\']*\'|"[^"]*")/';

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all($call, $source, $calls, PREG_OFFSET_CAPTURE)) {
                foreach ($calls[1] as [$argument, $offset]) {
                    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argument, $names);
                    foreach ($names[1] as $name) {
                        if (preg_match(self::PERMISSION_SHAPE, $name) && ! Gate::has($name)) {
                            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                            $references[$name][] = str_replace(base_path().'/', '', $file->getPathname()).":{$line}";
                        }
                    }
                }
            }
        }

        return $references;
    }

    /** @return array<string, array<int, string>> */
    private function phantoms(): array
    {
        $found = [];
        foreach ([$this->routeReferences(), $this->codeReferences()] as $references) {
            foreach ($references as $name => $places) {
                if (! in_array($name, $this->catalog, true)) {
                    $found[$name] = array_merge($found[$name] ?? [], $places);
                }
            }
        }
        ksort($found);

        return $found;
    }

    public function test_no_route_or_code_literal_names_a_permission_that_does_not_exist(): void
    {
        $unknown = array_diff_key($this->phantoms(), self::KNOWN_PHANTOMS);

        $this->assertSame([], array_map(fn ($places) => array_slice(array_unique($places), 0, 3), $unknown), 'phantom permission(s): create them in the catalog or fix the reference');
    }

    public function test_the_known_phantom_list_only_shrinks(): void
    {
        $phantoms = $this->phantoms();

        foreach (array_keys(self::KNOWN_PHANTOMS) as $name) {
            $this->assertArrayHasKey($name, $phantoms, "{$name} is not a referenced phantom any more: delete it from KNOWN_PHANTOMS");
            $this->assertNotContains($name, $this->catalog, "{$name} exists now: delete it from KNOWN_PHANTOMS");
        }
    }

    public function test_the_two_permissions_this_catalog_creates_are_real_and_referenced(): void
    {
        // They were phantoms before: routes, nav and the mobile menu used them but nothing created them.
        $references = array_merge_recursive($this->routeReferences(), $this->codeReferences());

        foreach (array_keys(RoleCatalog::NEW_PERMISSIONS) as $name) {
            $this->assertContains($name, $this->catalog);
            $this->assertArrayHasKey($name, $references, "{$name} is enforced somewhere");
        }
        $this->assertNotEmpty($this->routeReferences());
        $this->assertNotEmpty($this->codeReferences());
    }
}
