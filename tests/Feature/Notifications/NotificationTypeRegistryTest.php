<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Concerns\DeliversViaPreferences;
use App\Notifications\RfiObjectionNotification;
use App\Services\Notification\NotificationChannelResolver;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/** F-10 / F-16 / F-21: every typeKey() is seeded, and the resolver is lazy-load safe and memoised. */
class NotificationTypeRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, class-string> typeKey literal => declaring class */
    private function declaredTypeKeys(): array
    {
        $keys = [];
        $root = app_path('Notifications');

        foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
            $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Notification::class) || ! $reflection->hasMethod('typeKey')) {
                continue;
            }

            $method = $reflection->getMethod('typeKey');
            $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $source, $m);

            foreach ($m[1] as $key) {
                $keys[$key] = $class;
            }
        }

        return $keys;
    }

    public function test_every_type_key_declared_in_app_notifications_is_seeded(): void
    {
        $declared = $this->declaredTypeKeys();
        $this->assertNotEmpty($declared);

        $seeded = array_column(NotificationTypeSeeder::types(), 'key');

        foreach ($declared as $key => $class) {
            $this->assertContains($key, $seeded, "{$class} declares typeKey '{$key}' that NotificationTypeSeeder does not register");
        }
    }

    public function test_the_previously_missing_keys_are_registered(): void
    {
        $seeded = array_column(NotificationTypeSeeder::types(), 'key');

        foreach (['om.alert', 'hr.offboarding_initiated', 'attendance.absence_streak_escalation', 'rfi.objection', 'task.assigned', 'task.status_changed'] as $key) {
            $this->assertContains($key, $seeded);
        }
    }

    public function test_the_rfi_objection_notification_now_resolves_through_the_registry(): void
    {
        $this->assertContains(DeliversViaPreferences::class, class_uses_recursive(RfiObjectionNotification::class));

        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();
        $notification = (new ReflectionClass(RfiObjectionNotification::class))->newInstanceWithoutConstructor();

        $this->assertContains('database', $notification->via($user));

        NotificationType::where('key', 'rfi.objection')->update(['is_active' => false]);
        app(NotificationChannelResolver::class)->forgetTypes();
        $this->assertSame([], $notification->via($user));
    }

    public function test_the_resolver_does_not_lazy_load_preferences_on_a_queried_user(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->employee_id, 'category' => 'leave', 'channel' => 'push', 'enabled' => false]);
        $fresh = User::find($user->employee_id);   // a queried model: not exempt from preventLazyLoading

        $this->assertFalse($fresh->relationLoaded('notificationPreferences'));
        $channels = app(NotificationChannelResolver::class)->resolveForUser('leave.approved', $fresh);

        $this->assertTrue($fresh->relationLoaded('notificationPreferences'));
        $this->assertNotContains(PushChannel::class, $channels, 'the user muted push');
    }

    public function test_notification_types_are_looked_up_once_per_request(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $users = User::factory()->count(3)->create()->map(fn ($u) => User::with('notificationPreferences')->find($u->employee_id));
        $resolver = app(NotificationChannelResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach ($users as $user) {
            $resolver->resolveForUser('leave.approved', $user);
        }
        $typeQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'notification_types'))->count();

        $this->assertSame(1, $typeQueries);
    }
}
