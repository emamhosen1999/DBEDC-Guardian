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
