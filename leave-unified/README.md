# LeaveUnified — Integration Guide

## File Structure

```
Pages/
  LeavesUnified.jsx                   ← Main Inertia page (replace your 3 leave routes)

Components/
  LeaveUnified/
    AdminLeavesPanel.jsx              ← "All Leaves" tab (admin: filter, bulk approve/reject/delete)
    MyLeavesPanel.jsx                 ← "My Leaves" tab (employee personal view + balance cards)
    SummaryPanel.jsx                  ← "Summary" tab (employee pivot + department breakdown)
    AnalyticsPanel.jsx                ← "Analytics" tab (monthly trends, by-type, by-dept, top takers)
```

### Copy to your project
```
Pages/LeavesUnified.jsx
  → resources/js/Pages/LeavesUnified.jsx

Components/LeaveUnified/*
  → resources/js/Components/LeaveUnified/
```

---

## Laravel Route (web.php)

Replace your 3 existing leave routes with one:

```php
// Before:
Route::get('/leaves',          [LeaveController::class, 'adminIndex'])->name('leaves.index');
Route::get('/leaves/employee', [LeaveController::class, 'employeeIndex'])->name('leaves.employee');
Route::get('/leaves/summary',  [LeaveController::class, 'summary'])->name('leaves.summary');

// After (single route):
Route::get('/leaves', function () {
    return Inertia::render('LeavesUnified', [
        'title'       => 'Leave Management',
        'allUsers'    => User::with('department')->get(),
        'summaryData' => app(LeaveController::class)->getSummaryData(), // your existing logic
    ]);
})->middleware('auth')->name('leaves.index');
```

---

## API Routes Required (unchanged from before)

| Method | Route | Purpose |
|--------|-------|---------|
| GET  | `/leaves/paginate` | Paginated leaves (both admin+employee, uses `admin_view`, `view_all`, `user_id` params) |
| GET  | `/leaves/stats` | Stats summary (pending/approved/rejected counts) |
| POST | `/leaves/approve/{id}` | Approve single leave |
| POST | `/leaves/reject/{id}` | Reject single leave |
| POST | `/leaves/bulk-approve` | Bulk approve `{ leave_ids: [] }` |
| POST | `/leaves/bulk-reject` | Bulk reject `{ leave_ids: [] }` |
| GET  | `/leaves/analytics` | Analytics data (`year`, `department_id`) |
| GET  | `/leaves/summary/export` | Download summary (`format: excel|pdf`) |

---

## Tab Visibility Logic

| Tab | Shown when |
|-----|-----------|
| All Leaves | `auth.permissions` includes `leaves.view` |
| My Leaves | Always shown (every employee) |
| Summary | `auth.permissions` includes `leaves.view` |
| Analytics | `auth.permissions` includes `leaves.view` |

If a user is a regular employee without `leaves.view`, they only see **My Leaves**.

---

## Existing Components Reused (no changes)

- `@/Tables/LeaveEmployeeTable.jsx` — the actual leave table (HeroUI internally, untouched)
- `@/Forms/LeaveForm.jsx` — add/edit leave form (Radix UI internally, untouched)
- `@/Forms/DeleteLeaveForm.jsx` — delete confirmation (untouched)
- `@/Components/BulkLeave/BulkLeaveModal.jsx` — bulk apply (untouched)
- `@/Components/BulkDelete/BulkDeleteModal.jsx` — bulk delete (untouched)

---

## What Changed vs Old Pages

| Old | New |
|-----|-----|
| `LeavesAdmin.jsx` (standalone page) | `AdminLeavesPanel.jsx` inside unified tabs |
| `LeavesEmployee.jsx` (standalone page) | `MyLeavesPanel.jsx` inside unified tabs |
| `LeaveSummary.jsx` (HeroUI + framer-motion) | `SummaryPanel.jsx` — pure Radix UI, no motion |
| `LeaveAnalytics.jsx` (HeroUI + heroicons) | `AnalyticsPanel.jsx` — pure Radix UI, inline SVG charts |
| 3 nav menu items | 1 nav item → tabs inside |

### Key improvements in the new panels
- **No HeroUI** anywhere in the new panel files
- **No Tailwind** classes in the new panel files
- **No framer-motion** — removed entirely from SummaryPanel
- **No heroicons** — all icons from `@radix-ui/react-icons`
- **Inline SVG bar chart** in AnalyticsPanel — no recharts or chart.js dependency added
- **Header actions** per-tab (Add Leave, Bulk Leave, Refresh, Export buttons) injected via `onSetHeaderActions` callback — same pattern as AdminUnified
- **Leave balance cards** in MyLeavesPanel — shows used/remaining per leave type
- **Optimistic error handling** — every fetch has try/catch with user-facing toasts
