<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateFcmTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_their_fcm_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/update-fcm-token', ['fcm_token' => 'test-fcm-token-abc123'])
            ->assertOk()
            ->assertJson(['message' => 'FCM token updated successfully']);
    }
}
