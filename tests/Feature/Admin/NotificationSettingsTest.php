<?php

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
