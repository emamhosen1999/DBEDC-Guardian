<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Query-budget regression guard for the manager dashboard summary.
 *
 * The endpoint used to materialise the manager's ENTIRE in-scope daily-work id
 * set into a PHP array (`pluck('id')`) and then feed that array back into the
 * objection stats as a `whereIn(...)` binding list. On the live data the widest
 * manager scopes 19,021 daily works, so a single dashboard request built a 19k
 * element binding list — measured at ~970ms wall / ~420ms of SQL. The id set is
 * now passed as a SUBQUERY and the objection buckets are counted with one
 * GROUP BY instead of hydrating every objection and filtering three times.
 *
 * These tests pin the two properties that regression would break:
 *   1. The query count does NOT grow with the number of in-scope daily works.
 *   2. No SQL statement carries a per-daily-work binding list.
 */
class MobileManagerDashboardQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_query_count_does_not_grow_with_the_daily_work_set(): void
    {
        [$manager, $member] = $this->makeManagerWithTeam();

        // Baseline: a small scope.
        $this->seedDailyWorks($member, 3);
        $small = $this->countQueriesForSummary($manager);

        // Now grow the in-scope set by an order of magnitude. A correctly written
        // endpoint issues the SAME number of statements; an N+1 or a
        // count-in-a-loop would climb with the row count.
        $this->seedDailyWorks($member, 60);
        $large = $this->countQueriesForSummary($manager);

        // Non-growth, not exact equality: the first request of a test run also warms
        // Laravel's schema-introspection cache, so the second can legitimately issue
        // one FEWER statement. What must never happen is the count climbing.
        $this->assertLessThanOrEqual(
            $small,
            $large,
            "Manager dashboard query count grew from {$small} to {$large} when the in-scope "
            .'daily-work set grew from 3 to 63 rows — an N+1 has been reintroduced.'
        );
    }

    public function test_summary_never_binds_one_parameter_per_daily_work(): void
    {
        [$manager, $member] = $this->makeManagerWithTeam();
        $this->seedDailyWorks($member, 40);

        Sanctum::actingAs($manager);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query;
        });

        $this->getJson('/api/v1/manager/dashboard-summary')->assertOk();

        $worst = 0;
        $worstSql = '';
        foreach ($statements as $statement) {
            if (count($statement->bindings) > $worst) {
                $worst = count($statement->bindings);
                $worstSql = $statement->sql;
            }
        }

        // 40 daily works are in scope. If the id set were still materialised into
        // PHP and splatted back as bindings, at least one statement would carry
        // ~40 of them. The team is tiny, so a healthy request stays far below.
        $this->assertLessThan(
            20,
            $worst,
            "A statement bound {$worst} parameters for a 40-row daily-work scope, which means "
            ."the id set is being materialised into PHP again. SQL: {$worstSql}"
        );
    }

    public function test_objection_buckets_are_unchanged_by_the_subquery_rewrite(): void
    {
        [$manager, $member] = $this->makeManagerWithTeam();

        $work = DailyWork::factory()->forUsers($member, $member)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
        ]);

        $this->insertObjectionForDailyWork($work, $member->id, 'submitted');
        $this->insertObjectionForDailyWork($work, $member->id, 'submitted');
        $this->insertObjectionForDailyWork($work, $member->id, 'under_review');
        $this->insertObjectionForDailyWork($work, $member->id, 'resolved');

        // An objection attached to a daily work OUTSIDE the manager's scope must
        // still be excluded — the subquery must carry the same visibility filter
        // the plucked id list used to.
        $outsider = User::factory()->create([]);
        $outsideWork = DailyWork::factory()->forUsers($outsider, $outsider)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
        ]);
        $this->insertObjectionForDailyWork($outsideWork, $outsider->id, 'submitted');

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/manager/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.objections.submitted', 2)
            ->assertJsonPath('data.objections.under_review', 1)
            ->assertJsonPath('data.objections.total_active', 3);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function makeManagerWithTeam(): array
    {
        $manager = User::factory()->create([]);
        Role::findOrCreate('Project Manager');
        $manager->assignRole('Project Manager');

        $member = User::factory()->create(['report_to' => $manager->id]);

        return [$manager, $member];
    }

    private function seedDailyWorks(User $member, int $count): void
    {
        DailyWork::factory()
            ->count($count)
            ->forUsers($member, $member)
            ->create(['status' => DailyWork::STATUS_IN_PROGRESS]);
    }

    private function countQueriesForSummary(User $manager): int
    {
        Sanctum::actingAs($manager);

        $count = 0;
        $counting = true;
        DB::listen(function () use (&$count, &$counting) {
            if ($counting) {
                $count++;
            }
        });

        $this->getJson('/api/v1/manager/dashboard-summary')->assertOk();
        $counting = false;

        return $count;
    }

    private function insertObjectionForDailyWork(DailyWork $dailyWork, int $creatorId, string $status): int
    {
        $payload = [
            'title' => 'Budget Objection '.strtoupper($status),
            'category' => 'other',
            'description' => 'Objection for query-budget metrics.',
            'reason' => 'Need decision.',
            'status' => $status,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            $payload['daily_work_id'] = $dailyWork->id;
        }

        if (Schema::hasColumn('rfi_objections', 'type')) {
            $payload['type'] = DailyWork::TYPE_STRUCTURE;
        }

        $objectionId = (int) DB::table('rfi_objections')->insertGetId($payload);

        if (Schema::hasTable('daily_work_objection')) {
            DB::table('daily_work_objection')->insert([
                'daily_work_id' => $dailyWork->id,
                'rfi_objection_id' => $objectionId,
                'attached_by' => $creatorId,
                'attached_at' => now(),
                'attachment_notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $objectionId;
    }
}
