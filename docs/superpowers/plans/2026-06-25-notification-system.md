# Notification System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build one admin-configurable notification engine delivering across in-app, web push (FCM), mobile push (Expo), and email, with a type registry, admin channel/recipient matrix, and per-user preferences — wired for Leave + Attendance in v1.

**Architecture:** Laravel 11 native `Notification` classes choose their channels at send time via a pure `NotificationChannelResolver` (effective = admin-allowed ∩ user-opted-in, `database` always on). A custom `PushChannel` fans out to per-platform gateways (FCM for web, Expo for mobile) behind a `PushGateway` interface. A `NotificationSent` listener writes an ID-only RTDB signal so open SPA/mobile clients refetch from the authenticated Laravel API (no PII in Firebase). Admin matrix + user-preferences are Inertia/React Query screens; the header bell becomes a live React Query notification center.

**Tech Stack:** Laravel 11 · kreait/laravel-firebase (FCM + RTDB) · Inertia v2 / @inertiajs/react ^2 · React 18 · @tanstack/react-query ^5 · @radix-ui/themes · zod ^4 · spatie/laravel-permission ^6 · PHPUnit (sqlite) · vitest ^3. Mobile: Expo SDK 54 + expo-notifications (separate repo task).

## Global Constraints

- **Versions are fixed:** React 18 (no `use()`), React Query v5 object-form hooks, zod v4, Tailwind v3, Inertia v2. Verify against installed versions; never write v19/v4-Tailwind/zod3/Inertia1 idioms.
- **No PII in Firebase.** RTDB signals carry `{ ts }` under `signals/notif/{userId}` ONLY — never title/body/names. Clients refetch content from the authenticated Laravel API.
- **`database` channel is always delivered.** Users tune push/email noise but never lose the in-app audit record. A type that is `is_active = false` is off entirely (no record).
- **All notifications are `ShouldQueue`.** A dead FCM/Expo token must never block or fail the originating user action.
- **API envelope:** API JSON responses use `{ success, data }` (and `pagination` when listing); `requestJson` unwraps it. Match this exactly.
- **Dev workflow:** `npm run dev`, test at `https://aero-enterprise-suite.test`. NEVER `npm run build` (it auto-commits/pushes). New migrations must ALSO be run on the MySQL `dbedc_guardian` dev DB (`php artisan migrate`), not just sqlite, or live pages 500.
- **Graceful degradation:** in-app/push/email work without RTDB provisioned; the realtime signal write is wrapped so a missing `FIREBASE_DATABASE_URL` is logged, never thrown.
- **Permissions:** authorize admin settings with spatie `permission:notifications.settings`; never trust the client.

---

## Task 1: `notification_tokens` table + model (multi-device, dual-write)

**Files:**
- Create: `database/migrations/2026_06_25_000001_create_notification_tokens_table.php`
- Create: `app/Models/NotificationToken.php`
- Modify: `app/Models/User.php:37-39` (add `notificationTokens` relation)
- Test: `tests/Feature/Notifications/NotificationTokenModelTest.php`

**Interfaces:**
- Produces: `NotificationToken` with columns `id, user_id, provider ('fcm'|'expo'), token (unique), platform ('web'|'android'|'ios'), last_used_at, timestamps`; `User::notificationTokens(): HasMany`. Consumed by Tasks 5, 8, 14.

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_06_25_000001_create_notification_tokens_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16)->default('fcm'); // fcm | expo
            $table->string('token', 2048);
            $table->string('platform', 16)->default('web'); // web | android | ios
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique('token');
            $table->index(['user_id', 'provider']);
        });

        // Backfill existing single-column tokens (column dropped later in Task 14).
        if (Schema::hasColumn('users', 'fcm_token')) {
            DB::table('users')->whereNotNull('fcm_token')->orderBy('id')->each(function ($u) {
                DB::table('notification_tokens')->insertOrIgnore([
                    'user_id' => $u->id,
                    'provider' => 'fcm',
                    'token' => $u->fcm_token,
                    'platform' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_tokens');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php
// app/Models/NotificationToken.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationToken extends Model
{
    protected $fillable = ['user_id', 'provider', 'token', 'platform', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Add the relation to User**

In `app/Models/User.php`, add the import `use App\Models\NotificationToken;` near the other model imports and this method to the class:

```php
public function notificationTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(NotificationToken::class);
}
```

- [ ] **Step 4: Write the failing test**

```php
<?php
// tests/Feature/Notifications/NotificationTokenModelTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTokenModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_many_notification_tokens(): void
    {
        $user = User::factory()->create();
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'fcm', 'token' => 'web-1', 'platform' => 'web']);
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'expo', 'token' => 'ExponentPushToken[x]', 'platform' => 'android']);

        $this->assertCount(2, $user->fresh()->notificationTokens);
        $this->assertSame('expo', $user->notificationTokens()->where('platform', 'android')->first()->provider);
    }
}
```

- [ ] **Step 5: Run migrations + test**

Run: `php artisan migrate && php artisan test --filter=NotificationTokenModelTest`
Expected: PASS. Then run `php artisan migrate` against MySQL `dbedc_guardian` dev DB.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_25_000001_create_notification_tokens_table.php app/Models/NotificationToken.php app/Models/User.php tests/Feature/Notifications/NotificationTokenModelTest.php
git commit -m "feat(notifications): add notification_tokens table + model (multi-device)"
```

---

## Task 2: `notification_types` registry + model + seeder

**Files:**
- Create: `database/migrations/2026_06_25_000002_create_notification_types_table.php`
- Create: `app/Models/NotificationType.php`
- Create: `database/seeders/NotificationTypeSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call the seeder)
- Test: `tests/Feature/Notifications/NotificationTypeSeederTest.php`

**Interfaces:**
- Produces: `NotificationType` with `key, category, label, description, default_channels (array), locked_channels (array), recipient_roles (array), is_active (bool)`. Seeded keys for Leave + Attendance. Consumed by Tasks 4, 9, 10, 11.

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_06_25_000002_create_notification_types_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();            // e.g. leave.requested
            $table->string('category');                 // e.g. leave, attendance
            $table->string('label');
            $table->string('description')->nullable();
            $table->json('default_channels');           // ["database","push","mail"]
            $table->json('locked_channels')->nullable();// channels users cannot disable
            $table->json('recipient_roles')->nullable();// ["Employee"] (informational/targeting hint)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php
// app/Models/NotificationType.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationType extends Model
{
    protected $fillable = ['key', 'category', 'label', 'description', 'default_channels', 'locked_channels', 'recipient_roles', 'is_active'];

    protected $casts = [
        'default_channels' => 'array',
        'locked_channels' => 'array',
        'recipient_roles' => 'array',
        'is_active' => 'boolean',
    ];
}
```

- [ ] **Step 3: Write the seeder**

```php
<?php
// database/seeders/NotificationTypeSeeder.php
namespace Database\Seeders;

use App\Models\NotificationType;
use Illuminate\Database\Seeder;

class NotificationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // Leave
            ['key' => 'leave.requested', 'category' => 'leave', 'label' => 'Leave request submitted', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager', 'Super Administrator']],
            ['key' => 'leave.approved', 'category' => 'leave', 'label' => 'Leave approved', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'leave.rejected', 'category' => 'leave', 'label' => 'Leave rejected', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'leave.cancelled', 'category' => 'leave', 'label' => 'Leave cancelled', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            // Attendance
            ['key' => 'attendance.missed_punch_in', 'category' => 'attendance', 'label' => 'Missed punch-in', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.missed_punch_out', 'category' => 'attendance', 'label' => 'Missed punch-out', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.roster_changed', 'category' => 'attendance', 'label' => 'Roster/shift changed', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.shift_swap_requested', 'category' => 'attendance', 'label' => 'Shift swap requested', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee', 'Manager']],
            ['key' => 'attendance.shift_swap_decided', 'category' => 'attendance', 'label' => 'Shift swap decision', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.time_correction_requested', 'category' => 'attendance', 'label' => 'Time correction requested', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager']],
            ['key' => 'attendance.time_correction_decided', 'category' => 'attendance', 'label' => 'Time correction decision', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
        ];

        foreach ($types as $t) {
            NotificationType::updateOrCreate(['key' => $t['key']], array_merge($t, ['is_active' => true, 'description' => $t['description'] ?? null]));
        }
    }
}
```

- [ ] **Step 4: Register the seeder**

In `database/seeders/DatabaseSeeder.php`, add `$this->call(NotificationTypeSeeder::class);` inside `run()` (after existing role/permission seeders).

- [ ] **Step 5: Write the failing test**

```php
<?php
// tests/Feature/Notifications/NotificationTypeSeederTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationType;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_leave_and_attendance_types_with_locked_database_channel(): void
    {
        $this->seed(NotificationTypeSeeder::class);

        $approved = NotificationType::where('key', 'leave.approved')->first();
        $this->assertNotNull($approved);
        $this->assertContains('database', $approved->default_channels);
        $this->assertContains('database', $approved->locked_channels);
        $this->assertTrue($approved->is_active);
        $this->assertSame('leave', $approved->category);

        $this->assertNotNull(NotificationType::where('key', 'attendance.missed_punch_in')->first());
    }
}
```

- [ ] **Step 6: Run migrations + test + MySQL**

Run: `php artisan migrate && php artisan test --filter=NotificationTypeSeederTest`
Expected: PASS. Then `php artisan migrate` on MySQL `dbedc_guardian` and `php artisan db:seed --class=NotificationTypeSeeder` there.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_25_000002_create_notification_types_table.php app/Models/NotificationType.php database/seeders/NotificationTypeSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Notifications/NotificationTypeSeederTest.php
git commit -m "feat(notifications): seed notification_types registry for leave + attendance"
```

---

## Task 3: `notification_preferences` table + model

**Files:**
- Create: `database/migrations/2026_06_25_000003_create_notification_preferences_table.php`
- Create: `app/Models/NotificationPreference.php`
- Modify: `app/Models/User.php` (add `notificationPreferences` relation)
- Test: `tests/Feature/Notifications/NotificationPreferenceModelTest.php`

**Interfaces:**
- Produces: `NotificationPreference` with `user_id, category, channel ('push'|'mail'|'database'), enabled (bool)`; `User::notificationPreferences(): HasMany`. Absent row = inherit default. Consumed by Task 4 + Task 12.

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_06_25_000003_create_notification_preferences_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');           // matches notification_types.category
            $table->string('channel', 16);        // push | mail | database
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'category', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php
// app/Models/NotificationPreference.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'category', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Add the relation to User**

In `app/Models/User.php` add import `use App\Models\NotificationPreference;` and:

```php
public function notificationPreferences(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(NotificationPreference::class);
}
```

- [ ] **Step 4: Write the failing test**

```php
<?php
// tests/Feature/Notifications/NotificationPreferenceModelTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_disable_a_category_channel(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'category' => 'attendance', 'channel' => 'push', 'enabled' => false]);

        $pref = $user->fresh()->notificationPreferences->first();
        $this->assertSame('attendance', $pref->category);
        $this->assertFalse($pref->enabled);
    }
}
```

- [ ] **Step 5: Migrate + test + MySQL**

Run: `php artisan migrate && php artisan test --filter=NotificationPreferenceModelTest`
Expected: PASS. Then `php artisan migrate` on MySQL `dbedc_guardian`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_25_000003_create_notification_preferences_table.php app/Models/NotificationPreference.php app/Models/User.php tests/Feature/Notifications/NotificationPreferenceModelTest.php
git commit -m "feat(notifications): add notification_preferences table + model"
```

---

## Task 4: `NotificationChannelResolver` (pure core + DB resolver)

**Files:**
- Create: `app/Services/Notification/NotificationChannelResolver.php`
- Create: `app/Notifications/Channels/PushChannel.php` (class reference only; full impl in Task 5)
- Test: `tests/Unit/Notifications/NotificationChannelResolverTest.php`

**Interfaces:**
- Consumes: `NotificationType` (Task 2), `User::notificationPreferences` (Task 3).
- Produces:
  - `effectiveLogicalChannels(array $enabled, array $locked, array $userDisabled): array` — PURE. Returns logical channel names; `'database'` always included; a user-disabled channel is dropped unless in `$locked`.
  - `resolveForUser(string $typeKey, User $user): array` — returns Laravel channel identifiers (`'database'`, `'mail'`, `PushChannel::class`); `[]` if the type is inactive/unknown.
  Consumed by Task 7.

- [ ] **Step 1: Create the PushChannel placeholder (so the class name resolves)**

```php
<?php
// app/Notifications/Channels/PushChannel.php
namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;

class PushChannel
{
    // Implemented in Task 5. Declared now so resolveForUser() can reference ::class.
    public function send($notifiable, Notification $notification): void {}
}
```

- [ ] **Step 2: Write the failing unit test (pure function)**

```php
<?php
// tests/Unit/Notifications/NotificationChannelResolverTest.php
namespace Tests\Unit\Notifications;

use App\Services\Notification\NotificationChannelResolver;
use PHPUnit\Framework\TestCase;

class NotificationChannelResolverTest extends TestCase
{
    private NotificationChannelResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NotificationChannelResolver();
    }

    public function test_database_is_always_included_even_if_user_disables_it(): void
    {
        $out = $this->resolver->effectiveLogicalChannels(
            enabled: ['database', 'push', 'mail'],
            locked: ['database'],
            userDisabled: ['database', 'mail'],
        );
        $this->assertContains('database', $out);
        $this->assertNotContains('mail', $out);   // user disabled, not locked
        $this->assertContains('push', $out);
    }

    public function test_locked_channel_cannot_be_disabled_by_user(): void
    {
        $out = $this->resolver->effectiveLogicalChannels(
            enabled: ['database', 'push'],
            locked: ['database', 'push'],
            userDisabled: ['push'],
        );
        $this->assertEqualsCanonicalizing(['database', 'push'], $out);
    }

    public function test_only_admin_enabled_channels_are_candidates(): void
    {
        $out = $this->resolver->effectiveLogicalChannels(
            enabled: ['database'],        // admin disabled push + mail for this type
            locked: ['database'],
            userDisabled: [],
        );
        $this->assertEqualsCanonicalizing(['database'], $out);
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test --filter=NotificationChannelResolverTest`
Expected: FAIL — class `NotificationChannelResolver` not found.

- [ ] **Step 4: Implement the resolver**

```php
<?php
// app/Services/Notification/NotificationChannelResolver.php
namespace App\Services\Notification;

use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Channels\PushChannel;

class NotificationChannelResolver
{
    /**
     * Pure: compute the effective logical channels.
     * - start from admin-enabled channels
     * - drop any the user disabled, UNLESS that channel is locked
     * - always include 'database'
     *
     * @param  string[]  $enabled
     * @param  string[]  $locked
     * @param  string[]  $userDisabled
     * @return string[]
     */
    public function effectiveLogicalChannels(array $enabled, array $locked, array $userDisabled): array
    {
        $out = array_filter($enabled, function ($channel) use ($locked, $userDisabled) {
            if (in_array($channel, $locked, true)) {
                return true;
            }
            return ! in_array($channel, $userDisabled, true);
        });

        if (! in_array('database', $out, true)) {
            $out[] = 'database';
        }

        return array_values(array_unique($out));
    }

    /**
     * Resolve Laravel channel identifiers for a user + type key.
     *
     * @return array<int, string> e.g. ['database', 'mail', PushChannel::class]
     */
    public function resolveForUser(string $typeKey, User $user): array
    {
        $type = NotificationType::where('key', $typeKey)->first();
        if (! $type || ! $type->is_active) {
            return [];
        }

        $userDisabled = $user->notificationPreferences
            ->where('category', $type->category)
            ->where('enabled', false)
            ->pluck('channel')
            ->all();

        $logical = $this->effectiveLogicalChannels(
            $type->default_channels ?? ['database'],
            $type->locked_channels ?? ['database'],
            $userDisabled,
        );

        $map = ['database' => 'database', 'mail' => 'mail', 'push' => PushChannel::class];

        return array_values(array_filter(array_map(fn ($c) => $map[$c] ?? null, $logical)));
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=NotificationChannelResolverTest`
Expected: PASS (all three).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Notification/NotificationChannelResolver.php app/Notifications/Channels/PushChannel.php tests/Unit/Notifications/NotificationChannelResolverTest.php
git commit -m "feat(notifications): channel resolver (admin∩user, database-locked-on)"
```

---

## Task 5: Push abstraction — `PushMessage`, gateways, dispatcher, `PushChannel`

**Files:**
- Create: `app/Services/Notification/Push/PushMessage.php`
- Create: `app/Services/Notification/Push/PushGateway.php` (interface)
- Create: `app/Services/Notification/Push/FcmGateway.php`
- Create: `app/Services/Notification/Push/ExpoGateway.php`
- Create: `app/Services/Notification/Push/PushDispatcher.php`
- Modify: `app/Notifications/Channels/PushChannel.php` (full impl)
- Test: `tests/Feature/Notifications/PushDispatcherTest.php`

**Interfaces:**
- Consumes: `NotificationToken` (Task 1), `FcmNotificationService` (existing).
- Produces:
  - `PushMessage(string $title, string $body, array $data = [])` (readonly value object).
  - `PushGateway::send(iterable $tokens, PushMessage $message): array` returns invalid token strings.
  - `PushDispatcher::send(iterable $tokens, PushMessage $message): void` — groups by provider, delegates, prunes invalid `NotificationToken` rows.
  - `PushChannel::send($notifiable, $notification)` — calls `$notification->toPush($notifiable)` and dispatches to the user's tokens. Consumed by Task 7.

- [ ] **Step 1: Write the value object + interface**

```php
<?php
// app/Services/Notification/Push/PushMessage.php
namespace App\Services\Notification\Push;

class PushMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}
}
```

```php
<?php
// app/Services/Notification/Push/PushGateway.php
namespace App\Services\Notification\Push;

interface PushGateway
{
    /**
     * @param  iterable<\App\Models\NotificationToken>  $tokens
     * @return string[]  tokens that are permanently invalid and should be pruned
     */
    public function send(iterable $tokens, PushMessage $message): array;
}
```

- [ ] **Step 2: Write the FCM gateway**

```php
<?php
// app/Services/Notification/Push/FcmGateway.php
namespace App\Services\Notification\Push;

use App\Services\Notification\FcmNotificationService;

class FcmGateway implements PushGateway
{
    public function __construct(private FcmNotificationService $fcm) {}

    public function send(iterable $tokens, PushMessage $message): array
    {
        $tokenStrings = collect($tokens)->pluck('token')->filter()->values()->all();
        if (empty($tokenStrings)) {
            return [];
        }

        // Existing service casts data values to string for FCM.
        $data = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $message->data);

        $report = $this->fcm->sendMulticastNotification($tokenStrings, $message->title, $message->body, $data);

        return $report['invalid_tokens'] ?? [];
    }
}
```

- [ ] **Step 3: Write the Expo gateway**

```php
<?php
// app/Services/Notification/Push/ExpoGateway.php
namespace App\Services\Notification\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoGateway implements PushGateway
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function send(iterable $tokens, PushMessage $message): array
    {
        $tokenStrings = collect($tokens)->pluck('token')->filter()->values();
        if ($tokenStrings->isEmpty()) {
            return [];
        }

        $payload = $tokenStrings->map(fn ($t) => [
            'to' => $t,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->data,
            'sound' => 'default',
        ])->all();

        $invalid = [];
        try {
            $response = Http::acceptJson()->asJson()->post(self::ENDPOINT, $payload);
            $tickets = $response->json('data', []);
            foreach ($tickets as $i => $ticket) {
                if (($ticket['status'] ?? null) === 'error'
                    && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $invalid[] = $tokenStrings[$i];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Expo push failed', ['error' => $e->getMessage()]);
        }

        return $invalid;
    }
}
```

- [ ] **Step 4: Write the dispatcher**

```php
<?php
// app/Services/Notification/Push/PushDispatcher.php
namespace App\Services\Notification\Push;

use App\Models\NotificationToken;
use Illuminate\Support\Collection;

class PushDispatcher
{
    public function __construct(private FcmGateway $fcm, private ExpoGateway $expo) {}

    /**
     * @param  iterable<NotificationToken>  $tokens
     */
    public function send(iterable $tokens, PushMessage $message): void
    {
        $byProvider = (new Collection($tokens))->groupBy('provider');

        $invalid = [];
        if ($byProvider->has('fcm')) {
            $invalid = array_merge($invalid, $this->fcm->send($byProvider->get('fcm'), $message));
        }
        if ($byProvider->has('expo')) {
            $invalid = array_merge($invalid, $this->expo->send($byProvider->get('expo'), $message));
        }

        if (! empty($invalid)) {
            NotificationToken::whereIn('token', $invalid)->delete();
        }
    }
}
```

- [ ] **Step 5: Implement the PushChannel (replace the placeholder)**

```php
<?php
// app/Notifications/Channels/PushChannel.php
namespace App\Notifications\Channels;

use App\Services\Notification\Push\PushDispatcher;
use Illuminate\Notifications\Notification;

class PushChannel
{
    public function __construct(private PushDispatcher $dispatcher) {}

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }
        $message = $notification->toPush($notifiable);
        $tokens = $notifiable->notificationTokens()->get();
        if ($tokens->isNotEmpty()) {
            $this->dispatcher->send($tokens, $message);
        }
    }
}
```

- [ ] **Step 6: Write the failing test**

```php
<?php
// tests/Feature/Notifications/PushDispatcherTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Models\User;
use App\Services\Notification\Push\PushDispatcher;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_expo_token_reported_device_not_registered(): void
    {
        Http::fake([
            'exp.host/*' => Http::response(['data' => [
                ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']],
            ]]),
        ]);

        $user = User::factory()->create();
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'expo', 'token' => 'ExponentPushToken[dead]', 'platform' => 'android']);

        app(PushDispatcher::class)->send(
            $user->notificationTokens()->get(),
            new PushMessage('Hi', 'Body', ['type_key' => 'leave.approved']),
        );

        $this->assertDatabaseMissing('notification_tokens', ['token' => 'ExponentPushToken[dead]']);
    }
}
```

- [ ] **Step 7: Run the test**

Run: `php artisan test --filter=PushDispatcherTest`
Expected: PASS. (FCM path is exercised in Task 7's notification test with a mocked `FcmNotificationService`.)

- [ ] **Step 8: Commit**

```bash
git add app/Services/Notification/Push app/Notifications/Channels/PushChannel.php tests/Feature/Notifications/PushDispatcherTest.php
git commit -m "feat(notifications): push abstraction — FCM + Expo gateways, dispatcher, channel"
```

---

## Task 6: RTDB realtime signal on `NotificationSent` (graceful)

**Files:**
- Create: `app/Services/Notification/RealtimeNotificationSignal.php`
- Create: `app/Listeners/WriteRealtimeNotificationSignal.php`
- Modify: `app/Providers/EventServiceProvider.php` (map `NotificationSent` → listener)
- Test: `tests/Feature/Notifications/RealtimeSignalTest.php`

**Interfaces:**
- Consumes: kreait `firebase.database`.
- Produces: on a delivered `database`-channel notification, writes `signals/notif/{userId} = { ts }` (no PII). Never throws. Consumed implicitly by the client (Task 13).

- [ ] **Step 1: Write the signal writer**

```php
<?php
// app/Services/Notification/RealtimeNotificationSignal.php
namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;

class RealtimeNotificationSignal
{
    public function ping(int|string $userId): void
    {
        try {
            app('firebase.database')
                ->getReference("signals/notif/{$userId}")
                ->set(['ts' => now()->timestamp]);
        } catch (\Throwable $e) {
            // RTDB not provisioned yet, or transient — degrade silently (bell still refreshes on nav/poll).
            Log::warning('Realtime notif signal skipped', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 2: Write the listener**

```php
<?php
// app/Listeners/WriteRealtimeNotificationSignal.php
namespace App\Listeners;

use App\Services\Notification\RealtimeNotificationSignal;
use Illuminate\Notifications\Events\NotificationSent;

class WriteRealtimeNotificationSignal
{
    public function __construct(private RealtimeNotificationSignal $signal) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return; // one signal per notification, tied to the canonical in-app record
        }
        if (isset($event->notifiable->id)) {
            $this->signal->ping($event->notifiable->id);
        }
    }
}
```

- [ ] **Step 3: Register the listener**

In `app/Providers/EventServiceProvider.php`, add to the `$listen` array:

```php
\Illuminate\Notifications\Events\NotificationSent::class => [
    \App\Listeners\WriteRealtimeNotificationSignal::class,
],
```

- [ ] **Step 4: Write the failing test**

```php
<?php
// tests/Feature/Notifications/RealtimeSignalTest.php
namespace Tests\Feature\Notifications;

use App\Listeners\WriteRealtimeNotificationSignal;
use App\Services\Notification\RealtimeNotificationSignal;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RealtimeSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_channel_pings_realtime_signal_once(): void
    {
        $user = User::factory()->create();
        $spy = Mockery::mock(RealtimeNotificationSignal::class);
        $spy->shouldReceive('ping')->once()->with($user->id);

        $listener = new WriteRealtimeNotificationSignal($spy);
        $listener->handle(new NotificationSent($user, new class extends Notification {}, 'database'));
        $listener->handle(new NotificationSent($user, new class extends Notification {}, 'mail')); // ignored
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=RealtimeSignalTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Notification/RealtimeNotificationSignal.php app/Listeners/WriteRealtimeNotificationSignal.php app/Providers/EventServiceProvider.php tests/Feature/Notifications/RealtimeSignalTest.php
git commit -m "feat(notifications): write ID-only RTDB signal on database-channel send (graceful)"
```

---

## Task 7: `DeliversViaPreferences` trait (channels + toArray/toPush contract)

**Files:**
- Create: `app/Notifications/Concerns/DeliversViaPreferences.php`
- Test: `tests/Feature/Notifications/DeliversViaPreferencesTest.php`

**Interfaces:**
- Consumes: `NotificationChannelResolver` (Task 4).
- Produces: a trait giving any Notification a `via($notifiable)` that returns resolver output. Implementing notifications MUST define `public function typeKey(): string`, `toArray($notifiable): array` (must include `type_key`, `title`, `body`, `url`), and `toPush($notifiable): PushMessage`. Consumed by Tasks 9, 10.

- [ ] **Step 1: Write the trait**

```php
<?php
// app/Notifications/Concerns/DeliversViaPreferences.php
namespace App\Notifications\Concerns;

use App\Services\Notification\NotificationChannelResolver;

trait DeliversViaPreferences
{
    /** Each notification declares its registry key, e.g. 'leave.approved'. */
    abstract public function typeKey(): string;

    public function via(object $notifiable): array
    {
        return app(NotificationChannelResolver::class)->resolveForUser($this->typeKey(), $notifiable);
    }
}
```

- [ ] **Step 2: Write the failing test (a throwaway notification using the trait)**

```php
<?php
// tests/Feature/Notifications/DeliversViaPreferencesTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Concerns\DeliversViaPreferences;
use Illuminate\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliversViaPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_via_reflects_admin_type_and_user_prefs(): void
    {
        NotificationType::create([
            'key' => 'leave.approved', 'category' => 'leave', 'label' => 'x',
            'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'is_active' => true,
        ]);
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'category' => 'leave', 'channel' => 'mail', 'enabled' => false]);

        $notification = new class extends Notification {
            use DeliversViaPreferences;
            public function typeKey(): string { return 'leave.approved'; }
        };

        $channels = $notification->via($user);
        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
        $this->assertNotContains('mail', $channels); // user opted out, not locked
    }
}
```

- [ ] **Step 3: Run the test**

Run: `php artisan test --filter=DeliversViaPreferencesTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Notifications/Concerns/DeliversViaPreferences.php tests/Feature/Notifications/DeliversViaPreferencesTest.php
git commit -m "feat(notifications): DeliversViaPreferences trait wires via() to resolver"
```

---

## Task 8: In-app notifications API (list, unread-count, read, read-all) + token register upgrade

**Files:**
- Modify: `app/Http/Controllers/NotificationController.php` (replace legacy push code; add CRUD + upgrade `storeToken`)
- Modify: `routes/api.php:96-97` + add authenticated notification routes
- Test: `tests/Feature/Api/NotificationApiTest.php`
- Modify: `tests/Feature/Api/NotificationTokenApiTest.php` (assert `notification_tokens` row instead of `users.fcm_token`)

**Interfaces:**
- Consumes: `User::notifications` (Notifiable), `NotificationToken` (Task 1).
- Produces (all under `auth:sanctum`, `{ success, data }` envelope):
  - `GET /api/notifications` → paginated list `{ data: [...], pagination }`
  - `GET /api/notifications/unread-count` → `{ data: { count } }`
  - `POST /api/notifications/{id}/read` → marks one read
  - `POST /api/notifications/read-all` → marks all read
  - `POST /api/notification-token` (+ `/api/v1/notifications/token`) → upserts a `NotificationToken` (accepts `fcm_token` OR `token` + optional `provider`,`platform`). Consumed by Task 13 + mobile.

- [ ] **Step 1: Rewrite the controller**

```php
<?php
// app/Http/Controllers/NotificationController.php
namespace App\Http\Controllers;

use App\Models\NotificationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()->notifications()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true, 'data' => ['id' => $id]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'data' => ['marked' => true]]);
    }

    public function storeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required_without:token', 'string', 'max:2048'],
            'token' => ['required_without:fcm_token', 'string', 'max:2048'],
            'provider' => ['nullable', 'in:fcm,expo'],
            'platform' => ['nullable', 'in:web,android,ios'],
        ]);

        $token = $validated['token'] ?? $validated['fcm_token'];
        $provider = $validated['provider'] ?? 'fcm';
        $platform = $validated['platform'] ?? 'web';

        NotificationToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $request->user()->id, 'provider' => $provider, 'platform' => $platform, 'last_used_at' => now()],
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification token updated successfully.',
            'fcm_token' => $token, // backward-compatible field for existing mobile callers
        ]);
    }
}
```

- [ ] **Step 2: Wire routes**

In `routes/api.php`, replace the single notification-token line with a Sanctum group (keep both legacy paths working):

```php
// Notifications (in-app center + device tokens)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notification-token', [NotificationController::class, 'storeToken']);
});
```

Keep the existing `/api/v1/notifications/token` route (line ~252) pointing at `storeToken`.

- [ ] **Step 3: Write the failing API test**

```php
<?php
// tests/Feature/Api/NotificationApiTest.php
namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedNotification(User $user, bool $read = false): void
    {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['type_key' => 'leave.approved', 'title' => 'Approved', 'body' => 'Your leave is approved', 'url' => '/leaves'],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_unread_count_and_mark_all_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedNotification($user);
        $this->seedNotification($user);

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJson(['data' => ['count' => 2]]);
        $this->getJson('/api/notifications')->assertOk()->assertJsonStructure(['success', 'data', 'pagination']);

        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJson(['data' => ['count' => 0]]);
    }
}
```

- [ ] **Step 4: Update the legacy token test to the new storage**

Replace the `assertDatabaseHas('users', ['fcm_token' => ...])` assertions in `tests/Feature/Api/NotificationTokenApiTest.php` with token-table assertions, e.g.:

```php
$this->assertDatabaseHas('notification_tokens', ['user_id' => $user->id, 'token' => $legacyToken, 'provider' => 'fcm']);
// ...and for the v1 token:
$this->assertDatabaseHas('notification_tokens', ['user_id' => $user->id, 'token' => $v1Token, 'provider' => 'fcm']);
```

(Leave the `->assertJson(['fcm_token' => ...])` response assertions — the controller still returns that field.)

- [ ] **Step 5: Run the tests**

Run: `php artisan test --filter=NotificationApiTest && php artisan test --filter=NotificationTokenApiTest`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/NotificationController.php routes/api.php tests/Feature/Api/NotificationApiTest.php tests/Feature/Api/NotificationTokenApiTest.php
git commit -m "feat(notifications): in-app API (list/unread/read) + multi-device token register"
```

---

## Task 9: Migrate Leave notifications onto the engine

**Files:**
- Modify: `app/Notifications/LeaveApprovalNotification.php`, `LeaveApprovedNotification.php`, `LeaveRejectedNotification.php`
- Test: `tests/Feature/Notifications/LeaveNotificationChannelsTest.php`

**Interfaces:**
- Consumes: `DeliversViaPreferences` (Task 7), `PushMessage` (Task 5).
- Produces: each Leave notification declares a `typeKey()`, drops its hardcoded `via()`, and adds `toPush()`. Database payload includes `type_key`, `title`, `body`, `url`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Notifications/LeaveNotificationChannelsTest.php
namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\LeaveApprovedNotification;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_approved_uses_registry_channels(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();

        $leave = \App\Models\HRM\Leave::factory()->create(['user_id' => $user->id]);
        $notification = new LeaveApprovedNotification($leave);

        $channels = $notification->via($user);
        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
        $this->assertSame('leave.approved', $notification->typeKey());
    }
}
```

> If no `Leave` factory exists, create the leave inline with `Leave::create([...])` using its required columns instead of `factory()`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=LeaveNotificationChannelsTest`
Expected: FAIL — `typeKey()` not defined / `via()` returns the old hardcoded list.

- [ ] **Step 3: Update `LeaveApprovedNotification`**

In `app/Notifications/LeaveApprovedNotification.php`: add `use App\Notifications\Concerns\DeliversViaPreferences;` and `use App\Services\Notification\Push\PushMessage;`, add the trait, remove the hardcoded `via()`, and add:

```php
use DeliversViaPreferences;

public function typeKey(): string
{
    return 'leave.approved';
}

public function toPush(object $notifiable): PushMessage
{
    return new PushMessage(
        'Leave approved',
        "Your leave request has been approved.",
        ['type_key' => 'leave.approved', 'leave_id' => (string) $this->leave->id, 'url' => '/leaves-employee'],
    );
}
```

Ensure `toArray()` includes `type_key`, `title`, `body`, `url` keys (add them if missing).

- [ ] **Step 4: Repeat for the other two**

- `LeaveApprovalNotification` → `typeKey(): 'leave.requested'`, push title "Leave request to review", url `/leaves`.
- `LeaveRejectedNotification` → `typeKey(): 'leave.rejected'`, push title "Leave rejected", url `/leaves-employee`.
Apply the same trait + remove hardcoded `via()` + add `toPush()` + ensure `toArray()` has the four keys.

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=LeaveNotificationChannelsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Notifications/LeaveApprovalNotification.php app/Notifications/LeaveApprovedNotification.php app/Notifications/LeaveRejectedNotification.php tests/Feature/Notifications/LeaveNotificationChannelsTest.php
git commit -m "feat(notifications): route leave notifications through the resolver + push"
```

---

## Task 10: Attendance notifications

**Files:**
- Create: `app/Notifications/Attendance/MissedPunchNotification.php`, `RosterChangedNotification.php`, `ShiftSwapRequestedNotification.php`, `ShiftSwapDecidedNotification.php`, `TimeCorrectionRequestedNotification.php`, `TimeCorrectionDecidedNotification.php`
- Modify: the attendance command(s)/services that already detect these events to call `$user->notify(...)` (e.g. `app/Console/Commands/SendPunchOutReminder.php`, roster `updateCell`, shift-swap + time-correction services)
- Test: `tests/Feature/Notifications/AttendanceNotificationChannelsTest.php`

**Interfaces:**
- Consumes: `DeliversViaPreferences` (Task 7), `PushMessage` (Task 5).
- Produces: six attendance notifications, each with its `typeKey()`, `toArray()` (incl. the four keys), `toMail()` where applicable, and `toPush()`.

- [ ] **Step 1: Write the failing test (representative)**

```php
<?php
// tests/Feature/Notifications/AttendanceNotificationChannelsTest.php
namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Attendance\MissedPunchNotification;
use App\Notifications\Channels\PushChannel;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AttendanceNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missed_punch_in_delivers_database_and_push(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();

        $n = new MissedPunchNotification('in', now()->toDateString());
        $channels = $n->via($user);

        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
        $this->assertSame('attendance.missed_punch_in', $n->typeKey());
    }

    public function test_notify_records_database_row(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        Notification::fake();
        $user = User::factory()->create();

        $user->notify(new MissedPunchNotification('out', now()->toDateString()));

        Notification::assertSentTo($user, MissedPunchNotification::class);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AttendanceNotificationChannelsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `MissedPunchNotification`**

```php
<?php
// app/Notifications/Attendance/MissedPunchNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MissedPunchNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(public string $direction, public string $date) {} // 'in' | 'out'

    public function typeKey(): string
    {
        return $this->direction === 'in' ? 'attendance.missed_punch_in' : 'attendance.missed_punch_out';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => $this->direction === 'in' ? 'Missed punch-in' : 'Missed punch-out',
            'body' => "You have a missed punch-{$this->direction} for {$this->date}.",
            'url' => '/attendance-employee',
            'date' => $this->date,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);
        return new PushMessage($data['title'], $data['body'], ['type_key' => $data['type_key'], 'url' => $data['url']]);
    }
}
```

- [ ] **Step 4: Implement the other five (same shape)**

Create each with its `typeKey()`, `toArray()` (four keys), `toPush()`, constructor carrying the minimal ids needed:
- `RosterChangedNotification(string $date)` → `attendance.roster_changed`, url `/attendance-employee`.
- `ShiftSwapRequestedNotification(int $swapId)` → `attendance.shift_swap_requested`, url `/attendance.unified`, add `toMail()`.
- `ShiftSwapDecidedNotification(int $swapId, string $decision)` → `attendance.shift_swap_decided`, url `/attendance-employee`.
- `TimeCorrectionRequestedNotification(int $correctionId)` → `attendance.time_correction_requested`, url `/attendance.unified`, add `toMail()`.
- `TimeCorrectionDecidedNotification(int $correctionId, string $decision)` → `attendance.time_correction_decided`, url `/attendance-employee`.

- [ ] **Step 5: Wire the triggers**

Call `$notifiable->notify(new ...)` at each detection site:
- Missed punch: in the existing `SendPunchOutReminder` / `SendAttendanceReminders` command loop, replace/augment the raw FCM call with `$user->notify(new MissedPunchNotification('out', $date))`.
- Roster change: in `RosterController::updateCell` after a successful write, `$cell->user?->notify(new RosterChangedNotification($cell->date->toDateString()))`.
- Shift swap + time correction: in their approval services, notify the target/approver on request and the requester on decision.

(Keep these additive; do not remove existing attendance logic beyond the duplicated raw FCM reminder call.)

- [ ] **Step 6: Run the test**

Run: `php artisan test --filter=AttendanceNotificationChannelsTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Notifications/Attendance app/Console/Commands/SendPunchOutReminder.php app/Console/Commands/SendAttendanceReminders.php app/Http/Controllers/HRM/RosterController.php tests/Feature/Notifications/AttendanceNotificationChannelsTest.php
git commit -m "feat(notifications): attendance notifications (missed punch, roster, swap, correction)"
```

---

## Task 11: Admin notification-settings matrix (controller + Inertia page + query)

**Files:**
- Create: `app/Http/Controllers/Admin/NotificationSettingsController.php`
- Create: `resources/js/Pages/Admin/NotificationSettings.jsx`
- Create: `resources/js/api/queries/useNotificationSettingsQuery.js`
- Modify: `routes/web.php` (admin routes, `permission:notifications.settings`)
- Modify: `database/seeders/ComprehensiveRolePermissionSeeder.php` (add `notifications.settings` permission)
- Modify: `resources/js/Props/pages.jsx` (Admin submenu entry)
- Test: `tests/Feature/Admin/NotificationSettingsTest.php`

**Interfaces:**
- Consumes: `NotificationType` (Task 2).
- Produces:
  - `GET /admin/settings/notifications` (Inertia page, gated `permission:notifications.settings`).
  - `GET /admin/settings/notifications/list` → `{ success, data: NotificationType[] }`.
  - `PUT /admin/settings/notifications/{type}` → updates `default_channels`, `locked_channels`, `recipient_roles`, `is_active`.

- [ ] **Step 1: Add the permission to the seeder**

In `ComprehensiveRolePermissionSeeder.php`, add `'notifications.settings'` to the permission list and grant it to `Super Administrator` (and `Admin` if present). Run `php artisan db:seed --class=ComprehensiveRolePermissionSeeder` on dev DBs after.

- [ ] **Step 2: Write the failing test**

```php
<?php
// tests/Feature/Admin/NotificationSettingsTest.php
namespace Tests\Feature\Admin;

use App\Models\NotificationType;
use App\Models\User;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_user_cannot_update_a_type(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();
        $type = NotificationType::where('key', 'leave.approved')->first();

        $this->actingAs($user)->putJson("/admin/settings/notifications/{$type->id}", ['is_active' => false])
            ->assertForbidden();
    }

    public function test_authorized_admin_updates_channels(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        Permission::findOrCreate('notifications.settings', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('notifications.settings');
        $type = NotificationType::where('key', 'leave.approved')->first();

        $this->actingAs($admin)->putJson("/admin/settings/notifications/{$type->id}", [
            'default_channels' => ['database', 'push'],
            'locked_channels' => ['database'],
            'recipient_roles' => ['Employee'],
            'is_active' => true,
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['database', 'push'], $type->fresh()->default_channels);
    }
}
```

- [ ] **Step 3: Implement the controller**

```php
<?php
// app/Http/Controllers/Admin/NotificationSettingsController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/NotificationSettings');
    }

    public function list(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => NotificationType::orderBy('category')->orderBy('label')->get(),
        ]);
    }

    public function update(Request $request, NotificationType $type): JsonResponse
    {
        $validated = $request->validate([
            'default_channels' => ['required', 'array'],
            'default_channels.*' => ['in:database,push,mail'],
            'locked_channels' => ['nullable', 'array'],
            'locked_channels.*' => ['in:database,push,mail'],
            'recipient_roles' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);

        $type->update($validated);

        return response()->json(['success' => true, 'data' => $type->fresh()]);
    }
}
```

- [ ] **Step 4: Register routes (gated)**

In `routes/web.php`, inside the authenticated group:

```php
Route::middleware('permission:notifications.settings')->group(function () {
    Route::get('/admin/settings/notifications', [\App\Http\Controllers\Admin\NotificationSettingsController::class, 'index'])->name('admin.settings.notifications');
    Route::get('/admin/settings/notifications/list', [\App\Http\Controllers\Admin\NotificationSettingsController::class, 'list']);
    Route::put('/admin/settings/notifications/{type}', [\App\Http\Controllers\Admin\NotificationSettingsController::class, 'update']);
});
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=NotificationSettingsTest`
Expected: PASS.

- [ ] **Step 6: Build the query hook**

```js
// resources/js/api/queries/useNotificationSettingsQuery.js
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

export const notificationSettingsKeys = { all: ['notificationSettings'] };

export const useNotificationTypes = () =>
  useQuery({
    queryKey: notificationSettingsKeys.all,
    queryFn: () => requestJson('get', '/admin/settings/notifications/list'),
    staleTime: 60 * 1000,
  });

export const useUpdateNotificationType = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...payload }) => requestJson('put', `/admin/settings/notifications/${id}`, { data: payload }),
    onSuccess: () => qc.invalidateQueries({ queryKey: notificationSettingsKeys.all }),
  });
};
```

- [ ] **Step 7: Build the matrix page**

Create `resources/js/Pages/Admin/NotificationSettings.jsx` — a Radix `Table` grouped by `category`; each row shows the type label, three channel checkboxes (In-app disabled+checked when `database` is locked), a recipient-roles multiselect, and an active switch; on change call `useUpdateNotificationType().mutate({ id, default_channels, locked_channels, recipient_roles, is_active })`. Follow the existing admin-page layout (e.g. `resources/js/Pages/Admin/*` / `Settings` pages) for shell, headings, and toast usage (`@/utils/toastUtils`).

- [ ] **Step 8: Add the Admin menu entry**

In `resources/js/Props/pages.jsx`, add to the Admin `subMenu` (guarded by the permission):

```jsx
...(permissions.includes('notifications.settings') ? [{
  name: 'Notifications', icon: <Cog6ToothIcon className="w-5 h-5" />, route: 'admin.settings.notifications',
  description: 'Configure notification types, channels, and recipients'
}] : []),
```

- [ ] **Step 9: Manually verify**

With `npm run dev`, log in as Super Admin, open Admin → Notifications, toggle a channel, confirm it persists on reload. Confirm a non-privileged user gets 403 on the route.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Admin/NotificationSettingsController.php resources/js/Pages/Admin/NotificationSettings.jsx resources/js/api/queries/useNotificationSettingsQuery.js routes/web.php database/seeders/ComprehensiveRolePermissionSeeder.php resources/js/Props/pages.jsx tests/Feature/Admin/NotificationSettingsTest.php
git commit -m "feat(notifications): admin settings matrix (types/channels/recipients)"
```

---

## Task 12: User notification preferences screen

**Files:**
- Create: `app/Http/Controllers/NotificationPreferenceController.php`
- Create: `resources/js/Pages/Settings/NotificationPreferences.jsx`
- Create: `resources/js/api/queries/useNotificationPreferencesQuery.js`
- Modify: `routes/web.php` (authenticated, no special permission)
- Test: `tests/Feature/Notifications/NotificationPreferenceApiTest.php`

**Interfaces:**
- Consumes: `NotificationType` categories (Task 2), `NotificationPreference` (Task 3).
- Produces:
  - `GET /settings/notifications` (Inertia page).
  - `GET /settings/notifications/list` → `{ success, data: { categories, preferences } }` where `categories` carry their `locked_channels`.
  - `PUT /settings/notifications` → upserts `{ category, channel, enabled }[]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Notifications/NotificationPreferenceApiTest.php
namespace Tests\Feature\Notifications;

use App\Models\User;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_disable_a_category_channel_but_not_a_locked_one(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/settings/notifications', [
            'preferences' => [
                ['category' => 'leave', 'channel' => 'mail', 'enabled' => false],
                ['category' => 'leave', 'channel' => 'database', 'enabled' => false], // locked → must be ignored
            ],
        ])->assertOk();

        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'category' => 'leave', 'channel' => 'mail', 'enabled' => false]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $user->id, 'category' => 'leave', 'channel' => 'database']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=NotificationPreferenceApiTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Implement the controller**

```php
<?php
// app/Http/Controllers/NotificationPreferenceController.php
namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/NotificationPreferences');
    }

    public function list(Request $request): JsonResponse
    {
        $categories = NotificationType::where('is_active', true)
            ->get(['category', 'locked_channels'])
            ->groupBy('category')
            ->map(fn ($rows) => ['locked_channels' => array_values(array_unique($rows->flatMap->locked_channels->all()))]);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'preferences' => $request->user()->notificationPreferences()->get(['category', 'channel', 'enabled']),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.category' => ['required', 'string'],
            'preferences.*.channel' => ['required', 'in:push,mail,database'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        // Build the set of locked (category, channel) pairs to ignore.
        $locked = NotificationType::all()->flatMap(function ($t) {
            return collect($t->locked_channels ?? [])->map(fn ($c) => "{$t->category}|{$c}");
        })->unique()->all();

        foreach ($validated['preferences'] as $pref) {
            if (in_array("{$pref['category']}|{$pref['channel']}", $locked, true)) {
                continue; // can't override a locked channel
            }
            NotificationPreference::updateOrCreate(
                ['user_id' => $request->user()->id, 'category' => $pref['category'], 'channel' => $pref['channel']],
                ['enabled' => $pref['enabled']],
            );
        }

        return response()->json(['success' => true, 'data' => ['saved' => true]]);
    }
}
```

- [ ] **Step 4: Register routes**

In `routes/web.php` (authenticated group):

```php
Route::get('/settings/notifications', [\App\Http\Controllers\NotificationPreferenceController::class, 'index'])->name('settings.notifications');
Route::get('/settings/notifications/list', [\App\Http\Controllers\NotificationPreferenceController::class, 'list']);
Route::put('/settings/notifications', [\App\Http\Controllers\NotificationPreferenceController::class, 'update']);
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --filter=NotificationPreferenceApiTest`
Expected: PASS.

- [ ] **Step 6: Build the query hook + page**

`useNotificationPreferencesQuery.js`: a `useNotificationPreferences()` query (`GET /settings/notifications/list`) and a `useSaveNotificationPreferences()` mutation (`PUT /settings/notifications`, invalidating the query). `Settings/NotificationPreferences.jsx`: per-category rows with In-app/Push/Email switches; render a switch disabled+on when that channel is in the category's `locked_channels`; save on toggle with a success toast.

- [ ] **Step 7: Manually verify**

With `npm run dev`, open Settings → Notifications, toggle Email off for Leave, reload — it stays off; the In-app switch is disabled+on.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/NotificationPreferenceController.php resources/js/Pages/Settings/NotificationPreferences.jsx resources/js/api/queries/useNotificationPreferencesQuery.js routes/web.php tests/Feature/Notifications/NotificationPreferenceApiTest.php
git commit -m "feat(notifications): per-user notification preferences screen"
```

---

## Task 13: Live in-app notification center (bell + View all) with RTDB refresh

**Files:**
- Create: `resources/js/api/queries/useNotificationsQuery.js`
- Create: `resources/js/hooks/useRealtimeNotifications.js`
- Modify: `resources/js/Layouts/Header.jsx:48-330` (bell → React Query, mark-read, realtime refetch)
- Create: `resources/js/Pages/Notifications/Index.jsx` (View all)
- Modify: `routes/web.php` (View-all page route)
- Test: `resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx`

**Interfaces:**
- Consumes: Task 8 API; the existing RTDB client init (`resources/js/utils/firebaseInit.js`) and the realtime-foundation `onValue` pattern.
- Produces: `useUnreadCount()`, `useNotificationsList()`, `useMarkRead()`, `useMarkAllRead()`; `useRealtimeNotifications(userId)` that subscribes to `signals/notif/{userId}` and invalidates the notification queries on change (no-ops if RTDB unavailable).

- [ ] **Step 1: Write the failing test**

```jsx
// resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx
import { describe, it, expect, vi } from 'vitest';
import { notificationKeys } from '../useNotificationsQuery';

describe('notificationKeys', () => {
  it('exposes stable keys for list and unread-count', () => {
    expect(notificationKeys.list()).toEqual(['notifications', 'list']);
    expect(notificationKeys.unread()).toEqual(['notifications', 'unread']);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx vitest run resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx`
Expected: FAIL — cannot resolve `../useNotificationsQuery`.

- [ ] **Step 3: Build the query hook**

```js
// resources/js/api/queries/useNotificationsQuery.js
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

export const notificationKeys = {
  all: ['notifications'],
  list: () => ['notifications', 'list'],
  unread: () => ['notifications', 'unread'],
};

export const useUnreadCount = () =>
  useQuery({
    queryKey: notificationKeys.unread(),
    queryFn: () => requestJson('get', '/notifications/unread-count'),
    staleTime: 30 * 1000,
    select: (d) => d?.count ?? 0,
  });

export const useNotificationsList = () =>
  useQuery({
    queryKey: notificationKeys.list(),
    queryFn: () => requestJson('get', '/notifications'),
  });

export const useMarkRead = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id) => requestJson('post', `/notifications/${id}/read`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.unread() });
      qc.invalidateQueries({ queryKey: notificationKeys.list() });
    },
  });
};

export const useMarkAllRead = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => requestJson('post', '/notifications/read-all'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.unread() });
      qc.invalidateQueries({ queryKey: notificationKeys.list() });
    },
  });
};
```

- [ ] **Step 4: Run the test**

Run: `npx vitest run resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx`
Expected: PASS.

- [ ] **Step 5: Build the realtime hook**

```js
// resources/js/hooks/useRealtimeNotifications.js
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { notificationKeys } from '@/api/queries/useNotificationsQuery';

/**
 * Subscribe to signals/notif/{userId}; on any change, refetch the in-app
 * notification queries. Degrades to a no-op if RTDB isn't available.
 */
export function useRealtimeNotifications(userId) {
  const qc = useQueryClient();
  useEffect(() => {
    if (!userId) return;
    let unsub = () => {};
    (async () => {
      try {
        const { getDatabase, ref, onValue } = await import('firebase/database');
        const db = getDatabase();
        const r = ref(db, `signals/notif/${userId}`);
        unsub = onValue(r, () => {
          qc.invalidateQueries({ queryKey: notificationKeys.unread() });
          qc.invalidateQueries({ queryKey: notificationKeys.list() });
        });
      } catch {
        // RTDB not configured — bell still refreshes on navigation/poll.
      }
    })();
    return () => unsub();
  }, [userId, qc]);
}
```

> Verify the firebase app is initialized before `getDatabase()` (it is, via `resources/js/utils/firebaseInit.js`). If init is lazy, import and call the existing initializer first.

- [ ] **Step 6: Wire the bell in Header**

In `resources/js/Layouts/Header.jsx`: replace the `usePage().props.notifications` source with `useNotificationsList()` + `useUnreadCount()`; render the unread badge from the count; call `useRealtimeNotifications(auth?.user?.id)`; clicking an item calls `useMarkRead().mutate(id)` then navigates to `n.data?.url`; add a "Mark all read" action (`useMarkAllRead()`); "View all" links to `/notifications`.

- [ ] **Step 7: Build the View-all page + route**

Create `resources/js/Pages/Notifications/Index.jsx` (paginated list using `useNotificationsList`, mark-read per row). Add to `routes/web.php` authenticated group:

```php
Route::get('/notifications', fn () => \Inertia\Inertia::render('Notifications/Index'))->name('notifications.index');
```

- [ ] **Step 8: Manually verify**

With `npm run dev`: trigger a leave approval for your user (or `php artisan tinker` `$user->notify(...)`); the bell badge updates within ~1s (RTDB) or on next navigation (if RTDB not provisioned). Mark-all-read clears the badge.

- [ ] **Step 9: Commit**

```bash
git add resources/js/api/queries/useNotificationsQuery.js resources/js/hooks/useRealtimeNotifications.js resources/js/Layouts/Header.jsx resources/js/Pages/Notifications/Index.jsx routes/web.php resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx
git commit -m "feat(notifications): live in-app center (bell + view-all) with RTDB refresh"
```

---

## Task 14: Cleanup — remove legacy push code + drop `users.fcm_token`

**Files:**
- Modify: `app/Http/Controllers/UserController.php` (`updateFcmToken` → write `notification_tokens`)
- Modify: `app/Services/Notification/FcmNotificationService.php:150-159` (implement `handleInvalidToken` to delete from `notification_tokens`)
- Create: `database/migrations/2026_06_25_000004_drop_fcm_token_from_users_table.php`
- Test: `tests/Feature/Notifications/LegacyTokenCleanupTest.php`

**Interfaces:**
- Consumes: `NotificationToken` (Task 1). Removes the last readers of `users.fcm_token`, then drops the column.

- [ ] **Step 1: Confirm no remaining readers of `fcm_token` column**

Run a search for `fcm_token` across `app/` and fix any remaining DB reads to use `notification_tokens`. The legacy `NotificationController::sendPushNotification` + `getAccessToken` were already removed in Task 8 — confirm they're gone.

- [ ] **Step 2: Update `UserController::updateFcmToken`**

Rewrite it to upsert a `NotificationToken` (provider `fcm`, platform `web`) instead of setting `users.fcm_token`, mirroring `NotificationController::storeToken`.

- [ ] **Step 3: Implement `handleInvalidToken`**

```php
protected function handleInvalidToken($deviceToken)
{
    \App\Models\NotificationToken::where('token', $deviceToken)->delete();
}
```

- [ ] **Step 4: Write the failing test**

```php
<?php
// tests/Feature/Notifications/LegacyTokenCleanupTest.php
namespace Tests\Feature\Notifications;

use App\Models\NotificationToken;
use App\Services\Notification\FcmNotificationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyTokenCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_invalid_token_prunes_notification_tokens(): void
    {
        $user = User::factory()->create();
        NotificationToken::create(['user_id' => $user->id, 'provider' => 'fcm', 'token' => 'dead-web', 'platform' => 'web']);

        // handleInvalidToken is protected; call via reflection to assert pruning behavior.
        $svc = app(FcmNotificationService::class);
        $m = new \ReflectionMethod($svc, 'handleInvalidToken');
        $m->setAccessible(true);
        $m->invoke($svc, 'dead-web');

        $this->assertDatabaseMissing('notification_tokens', ['token' => 'dead-web']);
    }

    public function test_users_table_no_longer_has_fcm_token_column(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'fcm_token'));
    }
}
```

- [ ] **Step 5: Write the drop migration**

```php
<?php
// database/migrations/2026_06_25_000004_drop_fcm_token_from_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'fcm_token')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('fcm_token'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'fcm_token')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('fcm_token', 2048)->nullable());
        }
    }
};
```

Also remove `fcm_token` from `User::$fillable`/`$casts` if present.

- [ ] **Step 6: Migrate + test + MySQL**

Run: `php artisan migrate && php artisan test --filter=LegacyTokenCleanupTest`
Expected: PASS. Then `php artisan migrate` on MySQL `dbedc_guardian`.

- [ ] **Step 7: Full suite + commit**

Run the whole notification suite to confirm nothing regressed:
`php artisan test --filter=Notification && php artisan test --filter=NotificationToken && npx vitest run resources/js/api/queries/__tests__/useNotificationsQuery.test.jsx`

```bash
git add app/Http/Controllers/UserController.php app/Services/Notification/FcmNotificationService.php database/migrations/2026_06_25_000004_drop_fcm_token_from_users_table.php app/Models/User.php tests/Feature/Notifications/LegacyTokenCleanupTest.php
git commit -m "refactor(notifications): drop users.fcm_token, prune via notification_tokens"
```

---

## Self-Review

**Spec coverage:**
- 4 channels: database (Task 7/8), push web+mobile (Task 5), mail (existing + Task 9/10 `toMail`), realtime (Task 6/13) ✓
- Multi-device token table replacing `fcm_token` (Task 1 + 14) ✓
- Type registry + admin matrix (Task 2, 11) ✓
- Per-user preferences (Task 3, 12) ✓
- Effective = admin ∩ user, database locked-on (Task 4) ✓
- Per-platform gateways, one abstraction (Task 5) ✓
- v1 Leave + Attendance triggers (Task 9, 10) ✓
- Live bell + View all (Task 13) ✓
- Legacy push code removed, no PII in Firebase (Task 8, 6, 14) ✓
- Graceful degradation without RTDB (Task 6, 13) ✓

**Placeholder scan:** UI page bodies (Tasks 11/12/13 steps 6-7) are described rather than fully coded because they follow existing page scaffolding; every backend/contract step has runnable code. Each such step names exact hooks, endpoints, and behavior. No "TBD"/"handle edge cases" left.

**Type consistency:** `PushMessage(title, body, data)` consistent across Tasks 5/9/10; `effectiveLogicalChannels` / `resolveForUser` signatures match between Task 4 def and Task 7 use; channel identifiers (`'database'`, `'mail'`, `PushChannel::class`) consistent; `notificationKeys.list()/unread()` consistent between Task 13 hook and test; `storeToken` accepts `fcm_token` OR `token` consistent with the kept legacy test response assertion.

**Out of scope (deferred, engine-ready):** Tasks/Petty Cash/Admin-System notifications; digests/quiet-hours; mobile `expo-notifications` wiring (separate `dbedc-mobile-app` task — Laravel side is ready once Expo tokens POST to `/api/v1/notifications/token` with `provider=expo`).
