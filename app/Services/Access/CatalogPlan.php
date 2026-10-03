<?php

namespace App\Services\Access;

use App\Models\User;
use RuntimeException;

/**
 * The approved assignment plan (database/access-plans/catalog_v1.json): who holds which roles once the
 * role catalog is in force. Targets are ABSOLUTE (a role list per user, not a delta), which is what makes
 * `access:apply-catalog` idempotent and resumable.
 *
 * The plan hash is the sha256 of the canonical JSON (keys sorted, whitespace irrelevant); a dry-run, a
 * backup and an apply are tied together by it, so nobody applies a plan other than the one that was reviewed.
 */
final class CatalogPlan
{
    public const DEFAULT_PATH = 'database/access-plans/catalog_v1.json';

    /** @param array<string, mixed> $data */
    private function __construct(public readonly array $data, public readonly string $path) {}

    public static function load(?string $path = null): self
    {
        $path ??= base_path(self::DEFAULT_PATH);
        if (! is_file($path)) {
            throw new RuntimeException("Plan file not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['users']) || ! is_array($data['users'])) {
            throw new RuntimeException("Plan file is not a valid assignment plan: {$path}");
        }

        return new self($data, $path);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(self::canonical($this->data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, array<string, mixed>> employee id => entry */
    public function users(): array
    {
        $users = [];
        foreach ($this->data['users'] as $id => $entry) {
            $users[(string) $id] = $entry;
        }

        return $users;
    }

    /** @return array<int, string> */
    public function targetRoles(string $employeeId): array
    {
        return $this->users()[$employeeId]['target']['roles'] ?? [];
    }

    /** @return array<int, string> */
    public function targetDirectPermissions(string $employeeId): array
    {
        return $this->users()[$employeeId]['target']['direct_permissions'] ?? [];
    }

    /** @return array<int, string> */
    public function expectedLosses(string $employeeId): array
    {
        return $this->users()[$employeeId]['expected_losses'] ?? [];
    }

    /** @return array<string, array<int, string>> department id => default role names */
    public function departmentDefaultRoles(): array
    {
        $defaults = [];
        foreach (($this->data['department_default_roles'] ?? []) as $departmentId => $roles) {
            $defaults[(string) $departmentId] = array_values($roles);
        }

        return $defaults;
    }

    /** @return array<int, string> */
    public function rolesToDeleteWhenUnheld(): array
    {
        return array_values($this->data['delete_roles_when_unheld'] ?? []);
    }

    public function detachesRolesFromInactiveUsers(): bool
    {
        return (bool) ($this->data['detach_roles_from_inactive_users'] ?? false);
    }

    /**
     * What each user would lose, recomputed from the production role sets the plan was built against
     * and the catalog: the plan's `expected_losses` must equal it, or the plan was edited by hand.
     *
     * @return array<string, array<int, string>>
     */
    public function recomputedLosses(): array
    {
        $before = $this->data['role_sets_before'] ?? [];
        $every = $this->everyPermission();

        $losses = [];
        foreach ($this->users() as $id => $entry) {
            $target = $entry['target']['roles'];
            if (in_array('Super Administrator', $target, true)) {
                $losses[$id] = []; // Gate::before: the role set is not what lets the account act

                continue;
            }
            $was = $entry['current']['direct_permissions'] ?? [];
            foreach ($entry['current']['roles'] as $role) {
                $was = array_merge($was, $before[$role] ?? []);
            }
            $will = $entry['target']['direct_permissions'] ?? [];
            foreach ($target as $role) {
                $will = array_merge($will, RoleCatalog::permissionsFor($role, $every));
            }
            $lost = array_values(array_diff(array_unique($was), array_unique($will)));
            sort($lost);
            $losses[$id] = $lost;
        }

        return $losses;
    }

    /**
     * Checks that need no database. Empty = the plan is internally consistent.
     *
     * @return array<int, string>
     */
    public function staticErrors(): array
    {
        $errors = [];
        $catalog = RoleCatalog::roleNames();
        $every = $this->everyPermission();
        $recomputed = $this->recomputedLosses();

        foreach ($this->users() as $id => $entry) {
            $roles = $entry['target']['roles'] ?? [];
            foreach ($roles as $role) {
                if (! in_array($role, $catalog, true)) {
                    $errors[] = "{$id}: target role '{$role}' is not in the role catalog";
                }
            }
            if (! in_array(User::BASE_ROLE, $roles, true)) {
                $errors[] = "{$id}: every active user must keep the Employee role";
            }
            foreach ($entry['target']['direct_permissions'] ?? [] as $permission) {
                if (! in_array($permission, RoleCatalog::PER_PERSON_PERMISSIONS, true)) {
                    $errors[] = "{$id}: direct permission '{$permission}' is not a documented per-person exception";
                }
            }
            if (count(array_intersect($roles, RoleCatalog::SSD_EXEMPT_ROLES)) === 0) {
                $effective = $entry['target']['direct_permissions'] ?? [];
                foreach ($roles as $role) {
                    $effective = array_merge($effective, RoleCatalog::permissionsFor($role, $every));
                }
                foreach (RoleCatalog::SSD_PAIRS as [$a, $b, $label]) {
                    if (in_array($a, $effective, true) && in_array($b, $effective, true)) {
                        $errors[] = "{$id}: segregation of duties violated ({$label})";
                    }
                }
            }
            $expected = $entry['expected_losses'] ?? [];
            if (($recomputed[$id] ?? []) !== $expected) {
                $errors[] = "{$id}: expected_losses does not match the role sets (plan edited by hand?)";
            }
            if (preg_grep('/^tasks\./', $expected)) {
                $errors[] = "{$id}: tasks.* must stay (Q3) and cannot be an expected loss";
            }
        }

        foreach ($this->departmentDefaultRoles() as $departmentId => $roles) {
            foreach ($roles as $role) {
                if (! in_array($role, $catalog, true) || in_array($role, DepartmentDefaultRoles::FORBIDDEN, true)) {
                    $errors[] = "department {$departmentId}: '{$role}' cannot be a default role";
                }
            }
        }

        return $errors;
    }

    /** @return array<int, string> every permission name the plan knows (the rule roles need the full list) */
    private function everyPermission(): array
    {
        $names = array_keys(RoleCatalog::NEW_PERMISSIONS);
        foreach ($this->data['role_sets_before'] ?? [] as $set) {
            $names = array_merge($names, $set);
        }

        return array_values(array_unique($names));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => self::canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
