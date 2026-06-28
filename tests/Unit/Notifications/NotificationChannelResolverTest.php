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
