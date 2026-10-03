<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Access\AccessAudit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Catalog A1: every role / permission attach and detach lands in access_audit_logs.
 *
 * A subscriber (not auto-discovered handlers), so each event is recorded exactly once. The events
 * only fire while `permission.events_enabled` is true (config/permission.php). Spatie passes ids,
 * models or collections depending on the call, so the payload is resolved to names here.
 */
class RecordAccessAudit
{
    public function __construct(private readonly AccessAudit $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RoleAttached::class, fn (RoleAttached $e) => $this->roles('role.attached', $e->model, $e->rolesOrIds));
        $events->listen(RoleDetached::class, fn (RoleDetached $e) => $this->roles('role.detached', $e->model, $e->rolesOrIds));
        $events->listen(PermissionAttached::class, fn (PermissionAttached $e) => $this->permissions('permission.attached', $e->model, $e->permissionsOrIds));
        $events->listen(PermissionDetached::class, fn (PermissionDetached $e) => $this->permissions('permission.detached', $e->model, $e->permissionsOrIds));
    }

    private function roles(string $action, Model $model, mixed $rolesOrIds): void
    {
        $names = $this->names(Role::class, $rolesOrIds);
        $this->write($action, $model, ['roles' => $names], $action === 'role.detached');
    }

    private function permissions(string $action, Model $model, mixed $permissionsOrIds): void
    {
        $names = $this->names(Permission::class, $permissionsOrIds);
        $this->write($action, $model, ['permissions' => $names], $action === 'permission.detached');
    }

    /** @param array<string, array<int, string>> $payload */
    private function write(string $action, Model $model, array $payload, bool $detached): void
    {
        $subjectType = $model instanceof User ? 'user' : ($model instanceof Role ? 'role' : strtolower(class_basename($model)));
        $subjectId = $model instanceof Role ? $model->name : $model->getKey();

        $this->audit->record($action, $subjectType, $subjectId, $detached ? $payload : null, $detached ? null : $payload);
    }

    /** @return array<int, string> */
    private function names(string $class, mixed $value): array
    {
        $items = $value instanceof Collection ? $value->all() : (is_array($value) ? $value : [$value]);

        return collect($items)->map(function ($item) use ($class) {
            if ($item instanceof Model) {
                return $item->name;
            }
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                return $class::query()->whereKey($item)->value('name') ?? "#{$item}";
            }

            return (string) $item;
        })->values()->all();
    }
}
