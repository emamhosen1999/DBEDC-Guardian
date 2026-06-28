# Task 10b — Attendance Notification Wiring Report

## Sites Wired

### 1. `app/Console/Commands/SendPunchOutReminder.php` (line 40–48)
- **Notification**: `MissedPunchNotification('out', $date)`
- **Recipient**: Each user with a `punchin` but no `punchout` (all users already selected by the command)
- **Logic**: Added alongside the existing raw FCM `sendPushNotification` call; date derived from `now()->toDateString()`

### 2. `app/Console/Commands/SendAttendanceReminders.php` (line 67–77)
- **Notification**: `MissedPunchNotification('in', $date)`
- **Recipient**: Each user dispatched in the chunk loop (`$user`)
- **Logic**: Added alongside the existing `SendAttendanceReminder::dispatch(...)` job; the command sends a "don't be late" reminder to all users, which maps to the punch-in direction

### 3. `app/Http/Controllers/HRM/RosterController.php::updateCell` (line 100–111)
- **Notification**: `RosterChangedNotification($date)`
- **Recipient**: `User::find($data['user_id'])` — the affected employee
- **Logic**: Fires after `RosterDay::updateOrCreate(...)` succeeds; user fetched by the validated `user_id` in the request

### 4. `app/Http/Controllers/HRM/ShiftSwapController.php::store` (line 101–109)
- **Notification**: `ShiftSwapRequestedNotification($swap->id, $requester->name)`
- **Recipient**: `$counterparty` — the coworker targeted by the swap
- **Logic**: Fires after the `DB::transaction` that creates the swap record

### 5. `app/Http/Controllers/HRM/ShiftSwapController.php::approve` (line 237–248)
- **Notification**: `ShiftSwapDecidedNotification($swap->id, 'approved')`
- **Recipient**: `User::find($swap->requester_id)` — the employee who initiated the swap
- **Logic**: Fires after the DB transaction that approves and applies the swap

### 6. `app/Http/Controllers/HRM/ShiftSwapController.php::reject` (line 254–265)
- **Notification**: `ShiftSwapDecidedNotification($swap->id, 'rejected')`
- **Recipient**: `User::find($swap->requester_id)` — the swap requester
- **Logic**: Fires after `$swap->update(['status' => 'rejected', ...])`

### 7. `app/Services/Attendance/RegularizationService.php::request` (line 33–47)
- **Notification**: `TimeCorrectionRequestedNotification($r->id, $requester->name)`
- **Recipient**: First approver in the chain (`approval_chain[level=1].approver_id`)
- **Logic**: Fires only when `$r->status !== 'approved'` (i.e., chain is not empty and request is genuinely pending). If auto-approved (empty chain), no notification is sent.

### 8. `app/Services/Attendance/RegularizationService.php::approve` (line 52–62)
- **Notification**: `TimeCorrectionDecidedNotification($r->id, 'approved')`
- **Recipient**: `User::find($r->user_id)` — the employee who submitted the request
- **Logic**: Fires only when `$res['status'] === 'approved'` (final approval, not mid-chain)

### 9. `app/Http/Controllers/HRM/RegularizationController.php::reject` (line 65–76)
- **Notification**: `TimeCorrectionDecidedNotification($r->id, 'rejected')`
- **Recipient**: `User::find($r->user_id)` — the employee who submitted the request
- **Logic**: Fires when `$res['success']` is true after `AttendanceApprovalService::reject()`

## Sites Skipped

None. All four requested trigger areas were found and wired. The time-correction flow maps to `AttendanceRegularization` / `RegularizationService` (no separate `TimeCorrection` model exists in this codebase).

## Pattern Used

All notify calls wrapped in `try/catch (\Throwable $exception)` with `Log::warning(...)`, matching the pattern in `LeaveApprovalService::notifyCurrentApprover`.

## Tests

**File**: `tests/Feature/Notifications/AttendanceTriggerWiringTest.php`

```
php artisan test --filter=AttendanceTriggerWiringTest
```

```
PASS  Tests\Feature\Notifications\AttendanceTriggerWiringTest
✓ swap approve dispatches decided notification to requester           1.77s
✓ swap reject dispatches decided notification to requester            0.07s
✓ swap store dispatches requested notification to counterparty        0.07s
✓ roster update cell dispatches roster changed notification           0.08s

Tests: 4 passed (4 assertions)
Duration: 2.38s
```

**Full notification suite** (`php artisan test --filter=Notification`):

```
Tests: 25 passed (63 assertions)
Duration: 5.23s
```

All 25 notification tests green.
