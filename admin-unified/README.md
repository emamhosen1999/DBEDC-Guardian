# AdminUnified — Integration Guide

## File Structure

```
Pages/
  AdminUnified.jsx              ← Main page shell (register as your Inertia route)

Components/
  AdminUnified/
    UsersPanel.jsx              ← Users tab (table, device lock, role inline)
    RolesPanel.jsx              ← Roles & Permissions tab (4 sub-tabs)
    BiometricPanel.jsx          ← Biometric Devices tab (3 sub-tabs)
```

---

## Laravel Route (web.php)

```php
// Single Inertia route replaces your 3 separate pages
Route::get('/admin', function () {
    return Inertia::render('AdminUnified', [
        'title'                  => 'Admin Console',

        // Users panel
        'roles'                  => Role::with('permissions')->get(),
        'departments'            => Department::all(),
        'designations'           => Designation::all(),

        // Roles & Permissions panel
        'permissions'            => Permission::all(),
        'role_has_permissions'   => DB::table('role_has_permissions')->get(),
        'permissionsGrouped'     => Permission::all()->groupBy('module')
                                        ->map(fn($perms, $module) => [
                                            'label'       => $module,
                                            'permissions' => $perms->values(),
                                        ]),
        'can_manage_super_admin' => auth()->user()->can('manage super admin'),
        'users'                  => User::with('roles')->get(),

        // Biometric panel
        'devices'   => BiometricDevice::withCount('users')->get(),
        'employees' => User::select('id', 'name', 'employee_id')->get(),
    ]);
})->middleware(['auth', 'admin'])->name('admin.unified');
```

---

## Dependencies (already in your project)

| Package | Used for |
|---|---|
| `@radix-ui/themes` | All UI components |
| `@radix-ui/react-icons` | All icons |
| `axios` | API calls |
| `@inertiajs/react` | `Head`, `Link`, `router` |

No HeroUI. No Tailwind classes. No native HTML `<style>`.

---

## Props Contract

### AdminUnified.jsx (page)

| Prop | Type | Description |
|---|---|---|
| `title` | string | Page `<title>` |
| `roles` | Role[] | Roles with optional embedded `permissions[]` |
| `departments` | Dept[] | For users panel filters |
| `designations` | Desig[] | For users panel filters |
| `permissions` | Perm[] | All permissions |
| `role_has_permissions` | `{role_id, permission_id}[]` | Pivot table rows |
| `permissionsGrouped` | `{ [module]: { permissions[] } }` | For card grid |
| `can_manage_super_admin` | bool | Guard for Super Admin edits |
| `users` | User[] | With `roles[]` attached |
| `devices` | Device[] | With `users_count` |
| `employees` | User[] | Minimal — name, id, employee_id |

---

## API Routes Expected

### Users Panel
```
GET  /users/paginate          ?page, perPage, search, role, status
POST /user/{id}/toggleStatus
POST /user/{id}/updateRole    { roles: string[] }
DEL  /profile/delete          { user_id }
POST /admin/users/{userId}/devices/toggle
```

### Roles Panel
```
GET|POST       /api/roles
PUT|DEL        /api/roles/{id}
GET|POST       /api/permissions
PUT|DEL        /api/permissions/{id}
POST           /admin/roles/update-permission   { role_id, permission, action }
POST           /api/users/{id}/roles            { roles: id[] }
```

### Biometric Panel
```
POST  /biometric-devices/store
PUT   /biometric-devices/{id}/update
DEL   /biometric-devices/{id}/destroy
POST  /biometric-devices/{id}/ping
POST  /biometric-devices/{id}/regenerate-token
GET   /biometric-devices/{id}/users
POST  /biometric-devices/{id}/entry/add         { device_user_id }
POST  /biometric-devices/{id}/users/link        { device_user_id, user_id }
POST  /biometric-devices/{userId}/users/{id}/unlink
DEL   /biometric-devices/{userId}/users/{id}/remove
GET   /api/biometric-devices/logs/all
```

---

## Behaviour Notes

- **Default tab**: Users (set in `AdminUnified.jsx` `useState('users')`)
- **Header actions**: Each panel injects its own buttons via `onSetHeaderActions` callback — avoids prop-drilling
- **Device lock toggle**: Inline `Switch` in the Users table row — no drawer needed
- **Permission assignment**: Card grid on `md+` screens, flat checklist table on mobile (responsive via `isMobile` prop)
- **Optimistic updates**: Status toggle, device lock, and role change all update local state immediately and roll back on error
- **Pagination**: Custom page-number buttons using Radix `Button` + `IconButton` — no third-party pagination component
