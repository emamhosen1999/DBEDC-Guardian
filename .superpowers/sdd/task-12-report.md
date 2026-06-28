# Task 12 Report — Per-user notification preferences screen

## Files created/modified

| File | Action |
|------|--------|
| `app/Http/Controllers/NotificationPreferenceController.php` | Created — index/list/update |
| `resources/js/api/queries/useNotificationPreferencesQuery.js` | Created — useNotificationPreferences + useSaveNotificationPreferences |
| `resources/js/Pages/Settings/NotificationPreferences.jsx` | Created — per-category switch UI |
| `routes/web.php` | Modified — added 3 routes under auth+verified middleware |
| `tests/Feature/Notifications/NotificationPreferenceApiTest.php` | Created — TDD anchor test |

## TDD evidence

**RED (before implementation):**
```
FAILED Tests\Feature\Notifications\NotificationPreferenceApiTest > user can disable a category channel but not a locked one
Expected response status code [200] but received 404.
Tests: 1 failed (1 assertions), Duration: 2.16s
```

**GREEN (after controller + routes):**
```
PASS  Tests\Feature\Notifications\NotificationPreferenceApiTest
✓ user can disable a category channel but not a locked one   1.73s
Tests: 1 passed (3 assertions), Duration: 2.18s
```

**Full Notification suite (--filter=Notification):**
```
28 passed (69 assertions), Duration: 4.37s
```
All pre-existing notification tests remain green.

## esbuild syntax check

```
npx esbuild resources/js/Pages/Settings/NotificationPreferences.jsx --loader:.jsx=jsx --jsx=automatic > /dev/null
PASS: no syntax errors
```

## Self-review

- Controller correctly skips any (category, channel) pair in `locked_channels` — implemented by building a flat `category|channel` set from all NotificationType records then checking membership before each `updateOrCreate`.
- `list` endpoint groups by category and merges `locked_channels` across multiple types in the same category via `flatMap→unique`.
- React page uses optimistic local state (`localMap`) with revert on error; locked channels render `Switch disabled checked={true}` with a "req" hint.
- Follows established patterns: `App` layout, `ErrorBoundary`, `showToast`, React Query v5 object form, `requestJson` from `client.js`.

## Concerns

- The `list` endpoint returns the full `locked_channels` union across ALL active types in a category — if two types in the same category have different locked channels, both are locked at the user level. This is intentional and matches the brief.
- No navigation link added to reach `/settings/notifications` — that is outside Task 12 scope and left to a sidebar/nav integration task.
- The `.claire` typo worktree path produced a stray file; the correct file at `.claude/worktrees/notifications/...` was written and committed.
