<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Notifications\RfiObjectionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression coverage for the objection-domain unification (H-#).
 *
 * The mobile objection mutation endpoints previously fired the realtime signal
 * but NO notification — the recipient-resolution logic only lived in the web
 * controller. After consolidating both clients onto ObjectionService, a mobile
 * submit / resolve / reject must dispatch RfiObjectionNotification to exactly
 * the same recipients the web path used, and never to the actor.
 */
class MobileObjectionNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read the protected `event` off a RfiObjectionNotification for assertions.
     */
    private function eventOf(RfiObjectionNotification $notification): string
    {
        $property = new \ReflectionProperty($notification, 'event');
        $property->setAccessible(true);

        return (string) $property->getValue($notification);
    }

    private function makeObjection(DailyWork $dailyWork, User $creator, string $status): RfiObjection
    {
        $objection = new RfiObjection;
        $objection->title = 'Compaction not achieved';
        $objection->category = RfiObjection::CATEGORY_OTHER;
        $objection->description = 'Layer failed density test.';
        $objection->reason = 'Field density below spec.';
        $objection->status = $status;
        $objection->created_by = (int) $creator->id;

        // Legacy singular FK still present (and NOT NULL) in some schemas.
        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            $objection->setAttribute('daily_work_id', $dailyWork->id);
        }

        $objection->save();

        $objection->dailyWorks()->attach($dailyWork->id, [
            'attached_by' => $creator->id,
            'attached_at' => now(),
        ]);

        return $objection;
    }

    public function test_mobile_submit_notifies_stakeholders_but_not_the_actor(): void
    {
        Notification::fake();

        // The "submitted" escalation queries every manager role; all must exist
        // (as they do in production) for the Spatie role scope not to throw.
        foreach (['Super Admin', 'Admin', 'Project Manager', 'Consultant'] as $role) {
            Role::findOrCreate($role);
        }

        $incharge = User::factory()->create();
        $assigned = User::factory()->create();
        $creator = User::factory()->create();
        $actor = User::factory()->create();
        $actor->assignRole('Admin'); // privileged reviewer performing the submit

        $dailyWork = DailyWork::factory()->forUsers($incharge, $assigned)->create();
        $objection = $this->makeObjection($dailyWork, $creator, RfiObjection::STATUS_DRAFT);

        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/daily-works/{$dailyWork->id}/objections/{$objection->id}/submit")
            ->assertOk()
            ->assertJsonPath('success', true);

        // The incharge and assigned users of the linked daily work are notified.
        Notification::assertSentTo(
            $incharge,
            RfiObjectionNotification::class,
            fn (RfiObjectionNotification $n): bool => $this->eventOf($n) === RfiObjectionNotification::EVENT_SUBMITTED
        );
        Notification::assertSentTo($assigned, RfiObjectionNotification::class);

        // The actor never gets their own echo.
        Notification::assertNotSentTo($actor, RfiObjectionNotification::class);
    }

    public function test_mobile_resolve_notifies_the_objection_creator(): void
    {
        Notification::fake();

        Role::findOrCreate('Admin');

        $incharge = User::factory()->create();
        $creator = User::factory()->create();
        $actor = User::factory()->create();
        $actor->assignRole('Admin');

        $dailyWork = DailyWork::factory()->forUsers($incharge, $incharge)->create();
        $objection = $this->makeObjection($dailyWork, $creator, RfiObjection::STATUS_UNDER_REVIEW);

        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/daily-works/{$dailyWork->id}/objections/{$objection->id}/resolve", [
            'resolution_notes' => 'Re-tested and now passing.',
        ])->assertOk()->assertJsonPath('success', true);

        Notification::assertSentTo(
            $creator,
            RfiObjectionNotification::class,
            fn (RfiObjectionNotification $n): bool => $this->eventOf($n) === RfiObjectionNotification::EVENT_RESOLVED
        );
        Notification::assertNotSentTo($actor, RfiObjectionNotification::class);
    }

    public function test_mobile_reject_notifies_the_objection_creator(): void
    {
        Notification::fake();

        Role::findOrCreate('Admin');

        $incharge = User::factory()->create();
        $creator = User::factory()->create();
        $actor = User::factory()->create();
        $actor->assignRole('Admin');

        $dailyWork = DailyWork::factory()->forUsers($incharge, $incharge)->create();
        $objection = $this->makeObjection($dailyWork, $creator, RfiObjection::STATUS_UNDER_REVIEW);

        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/daily-works/{$dailyWork->id}/objections/{$objection->id}/reject", [
            'resolution_notes' => 'Insufficient evidence provided.',
        ])->assertOk()->assertJsonPath('success', true);

        Notification::assertSentTo(
            $creator,
            RfiObjectionNotification::class,
            fn (RfiObjectionNotification $n): bool => $this->eventOf($n) === RfiObjectionNotification::EVENT_REJECTED
        );
        Notification::assertNotSentTo($actor, RfiObjectionNotification::class);
    }
}
