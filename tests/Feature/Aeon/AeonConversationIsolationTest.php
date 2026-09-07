<?php

namespace Tests\Feature\Aeon;

use App\Models\Aeon\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AeonConversationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_list_is_scoped_by_string_employee_id(): void
    {
        $firstUser = User::factory()->create(['employee_id' => 'EMP-AEON-01']);
        $secondUser = User::factory()->create(['employee_id' => 'EMP-AEON-02']);

        $firstConversation = Conversation::create([
            'user_id' => $firstUser->employee_id,
            'title' => 'First user conversation',
        ]);
        Conversation::create([
            'user_id' => $secondUser->employee_id,
            'title' => 'Second user conversation',
        ]);

        $this->actingAs($firstUser)
            ->getJson('/aeon/conversations')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $firstConversation->id)
            ->assertJsonPath('0.user_id', 'EMP-AEON-01');
    }

    public function test_user_cannot_open_another_users_conversation(): void
    {
        $firstUser = User::factory()->create(['employee_id' => 'EMP-AEON-03']);
        $secondUser = User::factory()->create(['employee_id' => 'EMP-AEON-04']);
        $conversation = Conversation::create([
            'user_id' => $secondUser->employee_id,
            'title' => 'Private conversation',
        ]);

        $this->actingAs($firstUser)
            ->getJson("/aeon/conversations/{$conversation->id}")
            ->assertNotFound();
    }
}
