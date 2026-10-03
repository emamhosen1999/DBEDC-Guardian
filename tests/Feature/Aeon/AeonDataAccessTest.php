<?php

namespace Tests\Feature\Aeon;

use App\Contracts\Ai\AiChatResult;
use App\Contracts\Ai\AiProvider;
use App\Models\Aeon\Embedding;
use App\Models\User;
use App\Services\Aeon\AeonService;
use App\Services\Aeon\Data\AeonAccess;
use App\Services\Aeon\Data\QueryTool;
use App\Services\Aeon\RagService;
use App\Services\Aeon\Tools\AssetMaintenanceTool;
use App\Services\Aeon\Tools\ExecutiveBriefingTool;
use App\Services\Aeon\Tools\HumanResourcesTool;
use App\Services\Aeon\Tools\NavigateTool;
use App\Services\Aeon\Tools\PettyCashTool;
use App\Services\Aeon\Tools\PrepareOperationTool;
use App\Services\Aeon\Tools\QualityAssuranceTool;
use App\Services\Aeon\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * Aeon must never read more than the signed-in user may see: allowlisted tables only, the module's
 * permission, DepartmentScope row scope, and no compensation / identity-document columns. Tools are
 * driven directly (no network); the model is a fake provider that records what it is sent.
 */
class AeonDataAccessTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function query(User $actor, array $args): array
    {
        $result = app(QueryTool::class)->run($args, $actor->employee_id);
        $this->assertNotSame('query_failed', $result['data']['error'] ?? null, 'the query errored instead of being scoped: '.json_encode($args));

        return $result;
    }

    private function dump(array $result): string
    {
        return json_encode($result);
    }

    /** A user holding only direct permissions (no role), living in D1. */
    private function withPermissions(array $permissions, string $name = 'Direct Perms'): User
    {
        $user = $this->person($name, $this->d1, []);
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_plain_employee_cannot_query_module_or_pivot_tables(): void
    {
        $unknown = $this->query($this->e1, ['entity' => 'zz_does_not_exist', 'operation' => 'count']);

        foreach (['daily_works', 'quality_ncrs', 'model_has_roles', 'role_has_permissions', 'personal_access_tokens', 'sessions'] as $table) {
            $result = $this->query($this->e1, ['entity' => $table, 'operation' => 'count']);
            $this->assertSame('unknown_table', $result['data']['error'] ?? null, $table);
            // Indistinguishable from a table that does not exist.
            $this->assertSame(str_replace('zz_does_not_exist', $table, $unknown['text']), $result['text'], $table);
        }
    }

    public function test_department_admin_never_sees_other_department_employees_or_their_records(): void
    {
        $d2Users = User::whereIn('employee_id', ['90001', '90002', '90003'])->count();
        $this->assertSame(3, $d2Users);

        $count = $this->query($this->admin, ['entity' => 'users', 'operation' => 'count']);
        $this->assertSame(User::where('department_id', $this->d1->id)->count(), $count['data']['count']);

        $list = $this->query($this->admin, ['entity' => 'users', 'operation' => 'list', 'limit' => 50]);
        $ids = array_column($list['data']['records'], 'employee_id');
        $this->assertNotEmpty($ids);
        $this->assertEmpty(array_intersect($ids, ['90001', '90002', '90003']));

        $find = $this->query($this->admin, ['entity' => 'users', 'operation' => 'find', 'id' => '90001']);
        $this->assertStringNotContainsString(self::MARKER, $this->dump($find));
        $this->assertStringContainsString('not found', $find['text']);

        foreach ([['attendances', 90001], ['leaves', 90001], ['overtime_requests', 90001], ['offboardings', 90001], ['onboardings', 90001], ['assets', 90001], ['petty_cash_loans', 90001]] as [$table, $id]) {
            $count = $this->query($this->admin, ['entity' => $table, 'operation' => 'count', 'filters' => ['id' => $id]]);
            $this->assertSame(0, $count['data']['count'] ?? 0, "$table counted a D2 record");

            $list = $this->query($this->admin, ['entity' => $table, 'operation' => 'list', 'limit' => 50]);
            $this->assertStringNotContainsString(self::MARKER, $this->dump($list), "$table listed D2");
            $this->assertNotContains($id, array_column($list['data']['records'] ?? [], 'id'), "$table listed a D2 record");

            $find = $this->query($this->admin, ['entity' => $table, 'operation' => 'find', 'id' => $id]);
            $this->assertStringNotContainsString(self::MARKER, $this->dump($find), "$table found D2");
        }

        // Positive controls: his own department's records ARE visible, so the zeros above mean "scoped", not "broken".
        $this->assertSame(1, $this->query($this->admin, ['entity' => 'attendances', 'operation' => 'count'])['data']['count']);
        $this->assertSame(1, $this->query($this->admin, ['entity' => 'leaves', 'operation' => 'count'])['data']['count']);

        $group = $this->query($this->admin, ['entity' => 'leaves', 'operation' => 'group', 'group_by' => 'user_id']);
        $this->assertStringNotContainsString('90001', $this->dump($group));
    }

    public function test_petty_cash_transactions_follow_their_loan_owner(): void
    {
        $this->assertSame(0, $this->query($this->e1, ['entity' => 'petty_cash_transactions', 'operation' => 'count'])['data']['count']);
        $this->assertSame(0, $this->query($this->admin, ['entity' => 'petty_cash_transactions', 'operation' => 'count'])['data']['count']);
    }

    public function test_salary_is_never_returned_or_aggregated_without_compensation_permission(): void
    {
        $viewer = $this->withPermissions(['employees.view'], 'Viewer Only');
        $viewer->givePermissionTo('department.admin');
        $this->assertFalse($viewer->can('employees.compensation.view'));

        $access = app(AeonAccess::class);
        $this->assertNotContains('salary_amount', $access->columns($viewer, 'users'));
        $this->assertNotContains('salary_basis', $access->columns($viewer, 'users'));

        $list = $this->query($viewer, ['entity' => 'users', 'operation' => 'list', 'limit' => 50]);
        $this->assertStringNotContainsString('salary', $this->dump($list));

        $aggregate = $this->query($viewer, ['entity' => 'users', 'operation' => 'aggregate', 'aggregate_column' => 'salary_amount', 'aggregate_type' => 'sum']);
        $this->assertArrayNotHasKey('aggregate', $aggregate['data']);
        $this->assertArrayNotHasKey('value', $aggregate['data']);

        $group = $this->query($viewer, ['entity' => 'users', 'operation' => 'group', 'group_by' => 'salary_amount']);
        $this->assertStringNotContainsString('salary', $this->dump($group));

        // Filtering on salary would leak it by inference: the filter is ignored.
        $filtered = $this->query($viewer, ['entity' => 'users', 'operation' => 'count', 'filters' => ['salary_amount' => 91234]]);
        $this->assertSame($this->query($viewer, ['entity' => 'users', 'operation' => 'count'])['data']['count'], $filtered['data']['count']);

        $find = $this->query($viewer, ['entity' => 'users', 'operation' => 'find', 'id' => $this->e1->employee_id]);
        $this->assertArrayNotHasKey('salary_amount', $find['data']);
    }

    public function test_salary_requires_both_the_permission_and_scope_over_the_row(): void
    {
        $this->e1->forceFill(['salary_amount' => 45000])->save();
        $viewer = $this->withPermissions(['employees.view', 'employees.compensation.view', 'department.admin'], 'Comp Viewer');

        $find = $this->query($viewer, ['entity' => 'users', 'operation' => 'find', 'id' => $this->e1->employee_id]);
        $this->assertEquals(45000, $find['data']['salary_amount']);

        $aggregate = $this->query($viewer, ['entity' => 'users', 'operation' => 'aggregate', 'aggregate_column' => 'salary_amount', 'aggregate_type' => 'sum']);
        $this->assertEquals(45000, $aggregate['data']['value']); // D1 only: the D2 canaries' 91234 are out of scope
    }

    public function test_identity_documents_and_bank_details_are_never_returned_even_to_global_hr(): void
    {
        $this->hr->givePermissionTo('employees.compensation.view');
        $access = app(AeonAccess::class);

        foreach (['nid', 'passport_no', 'passport_exp_date', 'bank_name', 'bank_account_no', 'ifsc_code', 'pan_no', 'address', 'emergency_contact_primary_name'] as $column) {
            $this->assertNotContains($column, $access->columns($this->hr, 'users'), $column);
        }

        foreach (['find', 'list'] as $op) {
            $result = $this->query($this->hr, ['entity' => 'users', 'operation' => $op, 'id' => '90001', 'limit' => 50]);
            $this->assertStringNotContainsString(self::MARKER.'-NID', $this->dump($result));
            $this->assertStringNotContainsString(self::MARKER.'-ACC', $this->dump($result));
            $this->assertStringNotContainsString('bank_account', $this->dump($result));
        }

        $aggregate = $this->query($this->hr, ['entity' => 'users', 'operation' => 'aggregate', 'aggregate_column' => 'bank_account_no']);
        $this->assertArrayNotHasKey('aggregate', $aggregate['data']);
    }

    public function test_global_admin_reads_allowlisted_tables_but_not_pivot_or_token_tables(): void
    {
        $users = $this->query($this->hr, ['entity' => 'users', 'operation' => 'count']);
        $this->assertSame(User::count(), $users['data']['count']);
        $this->assertSame(1, $this->query($this->hr, ['entity' => 'users', 'operation' => 'count', 'filters' => ['employee_id' => '90001']])['data']['count']);
        $this->assertSame(1, $this->query($this->hr, ['entity' => 'attendances', 'operation' => 'count', 'filters' => ['id' => 90001]])['data']['count']);

        foreach (['model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'permissions', 'personal_access_tokens', 'refresh_tokens', 'sessions', 'user_sessions', 'self_administration_logs', 'audit_logs', 'attendance_audit_logs', 'notifications', 'company_settings', 'aeon_embeddings', 'aeon_messages', 'password_reset_tokens'] as $table) {
            $result = $this->query($this->hr, ['entity' => $table, 'operation' => 'count']);
            $this->assertSame('unknown_table', $result['data']['error'] ?? null, $table);
        }

        $superAdmin = $this->person('Root', null, ['Super Administrator']);
        foreach (['model_has_roles', 'personal_access_tokens', 'self_administration_logs'] as $table) {
            $this->assertSame('unknown_table', $this->query($superAdmin, ['entity' => $table, 'operation' => 'count'])['data']['error'] ?? null, $table);
        }
    }

    public function test_schema_text_given_to_the_model_lists_only_allowed_tables_and_columns(): void
    {
        $access = app(AeonAccess::class);

        $employee = $access->promptSchema($this->e1);
        foreach (['daily_works', 'quality_ncrs', 'model_has_roles', 'personal_access_tokens', 'salary_amount', 'bank_account_no', 'nid', 'password'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $employee, $hidden);
        }

        $admin = $access->promptSchema($this->admin);
        $this->assertStringContainsString('users', $admin);
        foreach (['model_has_roles', 'role_has_permissions', 'personal_access_tokens', 'self_administration_logs', 'bank_account_no', 'passport_no'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $admin, $hidden);
        }
        $this->assertSame([], array_diff(array_keys($access->tables($this->e1)), ['users', 'attendances', 'leaves', 'overtime_requests', 'attendance_regularizations', 'roster_days', 'petty_cash_loans', 'petty_cash_transactions']));
    }

    public function test_what_the_llm_is_sent_is_filtered_per_user(): void
    {
        config(['aeon.rag.enabled' => false]);
        $provider = new class implements AiProvider
        {
            public array $messages = [];

            public array $tools = [];

            public function chat(array $messages, array $tools = [], array $options = []): AiChatResult
            {
                $this->messages = $messages;
                $this->tools = $tools;

                return new AiChatResult(content: 'ok', model: 'fake');
            }

            public function embed(array $texts, array $options = []): array
            {
                return [];
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
        $this->app->instance(AiProvider::class, $provider);
        $this->app->forgetInstance(AeonService::class);
        $this->app->forgetInstance(ToolRegistry::class);

        app(AeonService::class)->chat('how many daily works are open?', null, $this->e1->employee_id);

        $system = $provider->messages[0]['content'];
        foreach (['daily_works', 'model_has_roles', 'quality_ncrs'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $system);
        }
        $names = array_column($provider->tools, 'name');
        $this->assertNotContains('petty_cash', $names);
        $this->assertNotContains('executive_briefing', $names);
        $this->assertNotContains('asset_maintenance', $names);
        $this->assertContains('query_data', $names);
        $this->assertStringNotContainsString('daily_works', json_encode($provider->tools));
    }

    public function test_specialised_tools_check_the_same_permission_as_the_page(): void
    {
        $this->assertSame('forbidden', app(PettyCashTool::class)->run(['action' => 'summary'], $this->e1->employee_id)['data']['error']);
        $this->assertSame('forbidden', app(HumanResourcesTool::class)->run(['action' => 'biometric_devices'], $this->e1->employee_id)['data']['error']);
        $this->assertSame('forbidden', app(HumanResourcesTool::class)->run(['action' => 'daily_summary'], $this->e1->employee_id)['data']['error']);
        $this->assertSame('forbidden', app(QualityAssuranceTool::class)->run(['action' => 'ncr_summary'], $this->e1->employee_id)['data']['error']);
        $this->assertSame('forbidden', app(ExecutiveBriefingTool::class)->run([], $this->e1->employee_id)['data']['error']);
        $this->assertSame('forbidden', app(AssetMaintenanceTool::class)->run(['action' => 'work_orders'], $this->e1->employee_id)['data']['error']);
    }

    public function test_daily_attendance_summary_counts_only_the_admins_department(): void
    {
        $result = app(HumanResourcesTool::class)->run(['action' => 'daily_summary', 'date' => self::DAY], $this->admin->employee_id);

        $this->assertSame(User::where('department_id', $this->d1->id)->count(), $result['data']['total']);
        $this->assertSame(1, $result['data']['present']); // e1's punch; the D2 canary's is out of scope
    }

    public function test_employee_cannot_navigate_or_prepare_forms_for_pages_they_cannot_open(): void
    {
        $nav = app(NavigateTool::class)->run(['destination' => 'roles_permissions'], $this->e1->employee_id);
        $this->assertSame('error', $nav['data']['status']);

        $nav = app(NavigateTool::class)->run(['destination' => 'roles_permissions'], $this->hr->employee_id);
        $this->assertContains($nav['data']['status'], ['success', 'error']); // never throws

        $form = app(PrepareOperationTool::class)->run(['entity' => 'role', 'operation' => 'create'], $this->e1->employee_id);
        $uri = $form['data']['uri'] ?? '';
        $this->assertStringNotContainsString('roles', $uri);
    }

    public function test_rag_never_retrieves_schema_or_modules_the_user_cannot_open(): void
    {
        $provider = new class implements AiProvider
        {
            public function chat(array $messages, array $tools = [], array $options = []): AiChatResult
            {
                return new AiChatResult(content: '');
            }

            public function embed(array $texts, array $options = []): array
            {
                return [[1.0, 0.0]];
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        foreach ([['schema', 'daily_works'], ['module', 'roles_permissions'], ['module', 'dashboard']] as [$type, $ref]) {
            Embedding::create(['source_type' => $type, 'source_ref' => $ref, 'title' => $ref, 'chunk_text' => "chunk $ref", 'vector' => [1.0, 0.0], 'dims' => 2, 'checksum' => sha1($ref)]);
        }

        $rag = new RagService($provider);
        $refs = array_column($rag->search('anything', 10, $this->e1), 'source_ref');

        $this->assertNotContains('daily_works', $refs);
        $this->assertNotContains('roles_permissions', $refs);
        $this->assertSame([], $rag->search('anything', 10, null));
    }

    public function test_no_other_table_leaks_through_a_sql_style_entity_name(): void
    {
        foreach (['users; drop table users', 'users union select * from model_has_roles', '../users', 'DB::table'] as $entity) {
            $result = $this->query($this->e1, ['entity' => $entity, 'operation' => 'list']);
            $this->assertSame('unknown_table', $result['data']['error'] ?? null);
        }
        $this->assertSame(1, DB::table('users')->where('employee_id', $this->e1->employee_id)->count());
    }
}
