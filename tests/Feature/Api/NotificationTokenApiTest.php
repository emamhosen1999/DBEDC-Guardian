<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_store_notification_token(): void
    {
        $this->postJson('/api/notification-token', [
            'fcm_token' => 'test-token',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/notifications/token', [
            'fcm_token' => 'test-token',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_store_notification_token_via_legacy_and_v1_routes(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $legacyToken = 'legacy-mobile-fcm-token';

        $legacyResponse = $this->postJson('/api/notification-token', [
            'fcm_token' => $legacyToken,
        ]);

        $legacyResponse->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notification token updated successfully.',
                'fcm_token' => $legacyToken,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'fcm_token' => $legacyToken,
        ]);

        $v1Token = 'v1-mobile-fcm-token';

        $v1Response = $this->postJson('/api/v1/notifications/token', [
            'fcm_token' => $v1Token,
        ]);

        $v1Response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Notification token updated successfully.',
                'fcm_token' => $v1Token,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'fcm_token' => $v1Token,
        ]);
    }

    public function test_notification_token_requires_fcm_token_field(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/notifications/token', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['fcm_token']);
    }
}
