<?php

// tests/Feature/Biometric/BiometricAlertPreferencesTest.php

namespace Tests\Feature\Biometric;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Biometric\BiometricDeviceSilentNotification;
use App\Notifications\Channels\PushChannel;
use App\Services\Notification\NotificationChannelResolver;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * biometric.device_silent as a MANAGEABLE alert.
 *
 * BiometricDeviceHealthAlert already delivered: DeliversProactiveAttendanceAlert
 * falls back to in-app + push for an unregistered type rather than going quiet.
 * But an alert that only exists in the fallback branch cannot be tuned from the
 * notification-preferences screen — the screen builds its rows from the active
 * NotificationType registry, so an unseeded type has no row to toggle. The
 * terminal-down alert was therefore loud and unchangeable at the same time.
 *
 * These tests pin down the two things that could go wrong once it IS registered:
 * that registering it made it manageable, and that no path through the registry
 * or a user's preferences can quietly take it to zero delivery. The command was
 * written to fail toward noise for exactly this reason — production had already
 * lost 3,672 scheduled runs to an alerting path that read as "already sent" and
 * delivered nothing — and a default of "no channels" would rebuild that silence
 * one layer up.
 */
class BiometricAlertPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    /** An admin of the kind BiometricDeviceHealthAlert::recipients() selects. */
    private function attendanceAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.settings');

        return $admin;
    }

    private function resolve(User $user): array
    {
        return app(NotificationChannelResolver::class)
            ->resolveForUser('biometric.device_silent', $user->fresh());
    }

    // ───────────────────────────── registration

    /**
     * Migration 2026_10_03_000004 registers the types in every fresh database. Tests about the
     * unregistered state start from an empty registry, removed row by row so the resolver's type
     * cache is flushed by the model events.
     */
    private function withoutRegisteredTypes(): void
    {
        NotificationType::all()->each->delete();
    }

    public function test_device_silent_type_is_registered_by_the_seeder(): void
    {
        $this->withoutRegisteredTypes();
        $this->assertNull(NotificationType::where('key', 'biometric.device_silent')->first());

        $this->seed(NotificationTypeSeeder::class);

        $type = NotificationType::where('key', 'biometric.device_silent')->first();

        $this->assertNotNull($type, 'biometric.device_silent must be registered or the preferences screen has no row to toggle.');
        $this->assertTrue($type->is_active);
        $this->assertSame('biometric', $type->category);
        $this->assertSame((new BiometricDeviceSilentNotification('x', 'y', 0))->typeKey(), $type->key);
    }

    public function test_registered_type_carries_the_channels_an_out_of_hours_alert_needs(): void
    {
        $this->seed(NotificationTypeSeeder::class);

        $type = NotificationType::where('key', 'biometric.device_silent')->first();

        $this->assertContains('database', $type->default_channels);
        $this->assertContains('push', $type->default_channels);
        $this->assertContains('mail', $type->default_channels, 'Mail is the only channel that lands on an admin with no push token at 02:00.');

        // The anti-silence guarantee: in-app cannot be switched off by anyone.
        $this->assertContains('database', $type->locked_channels);
        $this->assertNotEmpty($type->default_channels);
    }

    public function test_device_silent_gets_its_own_preference_category_separate_from_attendance(): void
    {
        $this->seed(NotificationTypeSeeder::class);

        // Preferences are stored per category, so sharing 'attendance' would mean
        // muting routine attendance push also mutes the terminal-down alert.
        $this->assertSame(
            'biometric',
            NotificationType::where('key', 'biometric.device_silent')->value('category')
        );
        $this->assertSame(
            0,
            NotificationType::where('category', 'biometric')->where('key', '!=', 'biometric.device_silent')->count()
        );
    }

    // ───────────────────────────── idempotency

    public function test_reseeding_does_not_duplicate_the_type(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $firstId = NotificationType::where('key', 'biometric.device_silent')->value('id');
        $countAfterFirstRun = NotificationType::count();

        $this->seed(NotificationTypeSeeder::class);
        $this->seed(NotificationTypeSeeder::class);

        $this->assertSame(1, NotificationType::where('key', 'biometric.device_silent')->count());
        $this->assertSame($countAfterFirstRun, NotificationType::count());
        // updateOrCreate on the unique `key` — same row, not a replacement.
        $this->assertSame($firstId, NotificationType::where('key', 'biometric.device_silent')->value('id'));
    }

    public function test_reseeding_does_not_clobber_a_user_preference(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $admin = $this->attendanceAdmin();

        // An admin who does not want e-mail for this, only in-app and push.
        NotificationPreference::create([
            'user_id' => $admin->id,
            'category' => 'biometric',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $this->seed(NotificationTypeSeeder::class);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $admin->id,
            'category' => 'biometric',
            'channel' => 'mail',
            'enabled' => false,
        ]);
        $this->assertSame(1, NotificationPreference::where('user_id', $admin->id)->count());

        $channels = $this->resolve($admin);
        $this->assertNotContains('mail', $channels, 'A stored opt-out must survive re-seeding.');
        $this->assertContains('database', $channels);
    }

    // ───────────────────────────── resolution

    public function test_admin_with_attendance_settings_resolves_channels_by_default(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $admin = $this->attendanceAdmin();

        // The permission is what BiometricDeviceHealthAlert actually selects on.
        $this->assertTrue(
            User::permission('attendance.settings')->whereKey($admin->id)->exists()
        );

        $channels = $this->resolve($admin);

        $this->assertNotEmpty($channels, 'Registering the type must not make it resolve to nothing.');
        $this->assertContains('database', $channels);
        $this->assertContains(PushChannel::class, $channels);
        $this->assertContains('mail', $channels);
    }

    public function test_registered_type_resolves_at_least_as_widely_as_the_unregistered_fallback(): void
    {
        $this->withoutRegisteredTypes();
        $admin = $this->attendanceAdmin();

        // Unregistered: the trait's fallback, in-app + push.
        $fallback = (new BiometricDeviceSilentNotification('Gate MB460', 'SN-1', 90, '2026-08-01 02:10'))->via($admin);
        $this->assertSame(['database', PushChannel::class], $fallback);

        $this->seed(NotificationTypeSeeder::class);

        $registered = (new BiometricDeviceSilentNotification('Gate MB460', 'SN-1', 90, '2026-08-01 02:10'))->via($admin->fresh());

        foreach ($fallback as $channel) {
            $this->assertContains($channel, $registered, 'Seeding must not narrow delivery below the fallback.');
        }
    }

    public function test_a_user_cannot_mute_the_alert_completely(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        $admin = $this->attendanceAdmin();

        foreach (['database', 'push', 'mail'] as $channel) {
            NotificationPreference::create([
                'user_id' => $admin->id,
                'category' => 'biometric',
                'channel' => $channel,
                'enabled' => false,
            ]);
        }

        $channels = $this->resolve($admin);

        // database is locked, so the 02:00 terminal failure still lands somewhere.
        $this->assertSame(['database'], $channels);
    }

    // ───────────────────────────── content

    public function test_alert_body_identifies_the_device_and_how_long_it_has_been_silent(): void
    {
        $notification = new BiometricDeviceSilentNotification('Gate MB460', 'SN-4471', 135, '2026-08-01 02:10');
        $payload = $notification->toArray(new User);

        $this->assertStringContainsString('Gate MB460', $payload['body']);
        $this->assertStringContainsString('SN-4471', $payload['body']);
        $this->assertStringContainsString('2 h 15 min', $payload['body']);
        $this->assertStringContainsString('2026-08-01 02:10', $payload['body']);
        // What it costs, and what to do about it.
        $this->assertStringContainsString('not reaching attendance', $payload['body']);
        $this->assertStringContainsString('power and network', $payload['body']);

        $this->assertSame('biometric.device_silent', $payload['type_key']);
        $this->assertSame('/settings/biometric-devices', $payload['url']);
        $this->assertSame('Gate MB460', $payload['device_name']);
        $this->assertSame('SN-4471', $payload['serial_number']);
        $this->assertSame(135, $payload['silent_minutes']);
        $this->assertSame('2 h 15 min', $payload['silent_for']);
        $this->assertSame('2026-08-01 02:10', $payload['last_seen']);
    }

    public function test_silence_duration_is_rendered_in_units_a_human_reads(): void
    {
        $render = fn (int $minutes) => (new BiometricDeviceSilentNotification('D', 'S', $minutes, '2026-08-01 02:10'))->humanSilence();

        $this->assertSame('45 min', $render(45));
        $this->assertSame('1 h', $render(60));
        $this->assertSame('2 h 15 min', $render(135));
        $this->assertSame('1 d', $render(1440));
        $this->assertSame('1 d 1 h', $render(1507));
    }

    public function test_never_seen_device_is_reported_as_a_provisioning_fault_not_a_failure(): void
    {
        $payload = (new BiometricDeviceSilentNotification('New MB460', 'SN-9', 0, null))->toArray(new User);

        $this->assertStringContainsString('New MB460', $payload['body']);
        $this->assertStringContainsString('SN-9', $payload['body']);
        $this->assertStringContainsString('never checked in', $payload['body']);
        // Sending someone to check the mains lead on a device that was never
        // provisioned wastes the callout.
        $this->assertStringContainsString('ADMS server address', $payload['body']);
        $this->assertStringNotContainsString('power and network', $payload['body']);
        $this->assertNull($payload['last_seen']);
    }

    public function test_push_payload_carries_the_device_identity_and_deep_link(): void
    {
        $push = (new BiometricDeviceSilentNotification('Gate MB460', 'SN-4471', 90, '2026-08-01 02:10'))
            ->toPush(new User);

        $this->assertSame('Biometric device offline', $push->title);
        $this->assertStringContainsString('Gate MB460', $push->body);
        $this->assertSame('biometric.device_silent', $push->data['type_key']);
        $this->assertSame('/settings/biometric-devices', $push->data['url']);
        $this->assertSame('SN-4471', $push->data['serial_number']);
    }

    public function test_mail_states_which_device_and_for_how_long(): void
    {
        $mail = (new BiometricDeviceSilentNotification('Gate MB460', 'SN-4471', 135, '2026-08-01 02:10'))
            ->toMail(new User);

        $this->assertStringContainsString('Gate MB460', $mail->subject);

        $rendered = implode("\n", array_merge($mail->introLines, $mail->outroLines));
        $this->assertStringContainsString('SN-4471', $rendered);
        $this->assertStringContainsString('2 h 15 min', $rendered);
        $this->assertStringContainsString('2026-08-01 02:10', $rendered);
        $this->assertStringContainsString('First thing to check', $rendered);
        $this->assertSame(url('/settings/biometric-devices'), $mail->actionUrl);
    }
}
