<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_profile_endpoints(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->putJson('/api/v1/profile', [])->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_mobile_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Mobile User',
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/profile');

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Mobile User');
    }

    public function test_authenticated_user_can_update_mobile_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'name' => 'Updated Mobile Name',
            'phone' => '+8801700000000',
            'address' => 'Dhaka, Bangladesh',
            'about' => 'Updated from mobile app',
            'emergency_contact_primary_name' => 'Emergency Contact',
            'emergency_contact_primary_phone' => '+8801800000000',
        ];

        $response = $this->putJson('/api/v1/profile', $payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated successfully.',
            ])
            ->assertJsonPath('data.name', 'Updated Mobile Name')
            ->assertJsonPath('data.phone', '+8801700000000');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Mobile Name',
            'phone' => '+8801700000000',
            'address' => 'Dhaka, Bangladesh',
        ]);
    }

    public function test_mobile_profile_update_validates_required_name(): void
    {
        $user = User::factory()->create([
            'active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/profile', [
            'name' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
