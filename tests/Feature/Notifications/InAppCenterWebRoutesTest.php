<?php
// tests/Feature/Notifications/InAppCenterWebRoutesTest.php
namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards the SPA contract: the React Query bell/center calls the SESSION-authed
 * web routes (/notifications/list, /unread-count, /{id}/read, /read-all) — NOT
 * the /api routes. A regression here silently breaks the entire in-app center.
 */
class InAppCenterWebRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function seedNotification(User $user, bool $read = false): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['type_key' => 'leave.approved', 'title' => 'Approved', 'body' => 'Your leave is approved', 'url' => '/leaves'],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_web_in_app_center_routes_return_json_for_session_user(): void
    {
        $user = User::factory()->create();
        $this->seedNotification($user);
        $n = $this->seedNotification($user);

        // list — must be JSON envelope, NOT the Inertia HTML page
        $this->actingAs($user)->getJson('/notifications/list')
            ->assertOk()
            ->assertJsonStructure(['success', 'data', 'pagination']);

        $this->actingAs($user)->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['data' => ['count' => 2]]);

        $this->actingAs($user)->postJson("/notifications/{$n->id}/read")->assertOk();
        $this->actingAs($user)->getJson('/notifications/unread-count')
            ->assertOk()->assertJson(['data' => ['count' => 1]]);

        $this->actingAs($user)->postJson('/notifications/read-all')->assertOk();
        $this->actingAs($user)->getJson('/notifications/unread-count')
            ->assertOk()->assertJson(['data' => ['count' => 0]]);
    }

    public function test_in_app_center_routes_require_auth(): void
    {
        // Web routes redirect guests (302), not 401.
        $this->getJson('/notifications/unread-count')->assertStatus(401);
    }
}
