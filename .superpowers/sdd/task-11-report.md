# Task 11 Report: Admin Notification Settings Matrix

## Files Created / Modified

| Action | Path |
|--------|------|
| Created | `app/Http/Controllers/Admin/NotificationSettingsController.php` |
| Created | `resources/js/Pages/Admin/NotificationSettings.jsx` |
| Created | `resources/js/api/queries/useNotificationSettingsQuery.js` |
| Created | `tests/Feature/Admin/NotificationSettingsTest.php` |
| Modified | `routes/web.php` — added 3 routes under `permission:notifications.settings` middleware |
| Modified | `database/seeders/ComprehensiveRolePermissionSeeder.php` — added `notifications.settings` permission to `admin` module |
| Modified | `resources/js/Props/pages.jsx` — appended `Notifications` entry to Admin subMenu |

## TDD Evidence

**RED** (before controller/routes): 2 tests FAILED — both returned 404.

**GREEN** (after implementation): `php artisan test --filter=NotificationSettingsTest` → 2 passed (3 assertions, 2.90s)

```
PASS Tests\Feature\Admin\NotificationSettingsTest
✓ unauthorized user cannot update a type
✓ authorized admin updates channels
Tests: 2 passed (3 assertions)
```

**Full suite**: `php artisan test --filter=Notification` → 27 passed (66 assertions) — no regressions.

## esbuild Syntax Check

```
npx esbuild resources/js/Pages/Admin/NotificationSettings.jsx --loader:.jsx=jsx --jsx=automatic > /dev/null
→ esbuild OK
```

## Self-Review

- Controller follows existing `{success, data}` envelope convention.
- Routes are gated with `['auth', 'verified', 'permission:notifications.settings']` matching the existing pattern.
- Query hook uses React Query v5 object-form pattern identical to `useRequestLogsQuery.js`.
- Page uses `App` layout with `.layout` static property, matching `CompanySettings.jsx` and `RequestLogs.jsx`.
- Toast uses `showToast.success` / `showToast.error` from `@/utils/toastUtils`.
- In-app (database) checkbox is disabled+checked when the channel is in `locked_channels` — requirement met.
- Menu entry guarded by `permissions.includes('notifications.settings')`.

## Concerns / Notes

1. **Seeder must be re-run on dev/prod** after this merge: `php artisan db:seed --class=ComprehensiveRolePermissionSeeder` — without it, `notifications.settings` permission won't exist in the live DB, and the routes will return 403 for everyone including Super Administrator.
2. The `ALL_ROLES` constant in the JSX is hardcoded to the 5 common roles. If new roles are added, this list needs updating — or it could be fetched from `/api/v1/roles` in a follow-up.
3. The `pages.jsx` test file has 5 pre-existing failing tests (stale Workspace dropdown nav tests); this change does not add new failures — verified by visual inspection (no test runner for JSX files in this project).
