<?php

namespace Tests\Feature\RequestLogs;

use App\Models\AccessAuditLog;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RequestLogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['request-logs.enabled' => false]); // the test's own requests must not add rows
        foreach (['request_logs.view', 'request_logs.delete', 'request_logs.clear_all', 'attendance.settings'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('Super Administrator', 'web');
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function log(array $attrs = []): RequestLog
    {
        return RequestLog::create($attrs + ['method' => 'GET', 'url' => 'https://x.test/a', 'response_status' => 200]);
    }

    public function test_attendance_settings_alone_no_longer_grants_access(): void
    {
        $user = $this->userWith('attendance.settings');

        $this->actingAs($user)->get(route('request-logs.index'))->assertForbidden();
        $this->actingAs($user)->getJson(route('request-logs.list'))->assertForbidden();
    }

    public function test_viewer_can_list_filter_paginate_show_and_export_but_not_delete(): void
    {
        $viewer = $this->userWith('request_logs.view');
        $this->log(['method' => 'POST', 'response_status' => 500, 'url' => 'https://x.test/needle']);
        $keep = $this->log();
        foreach (range(1, 6) as $i) {
            $this->log();
        }

        $this->actingAs($viewer)->get(route('request-logs.index'))->assertOk();

        $this->actingAs($viewer)->getJson(route('request-logs.list', ['status' => 500]))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.response_body');
        $this->actingAs($viewer)->getJson(route('request-logs.list', ['search' => 'needle', 'method' => 'post']))
            ->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($viewer)->getJson(route('request-logs.list', ['per_page' => 5]))
            ->assertOk()->assertJsonPath('per_page', 5)->assertJsonCount(5, 'data');

        $this->actingAs($viewer)->getJson(route('request-logs.show', $keep->id))->assertOk()->assertJsonPath('id', $keep->id);
        $this->actingAs($viewer)->get(route('request-logs.export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->actingAs($viewer)->deleteJson(route('request-logs.destroy', $keep->id))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('request-logs.bulk-delete'), ['ids' => [$keep->id]])->assertForbidden();
        $this->assertDatabaseHas('request_logs', ['id' => $keep->id]);
    }

    public function test_delete_permission_allows_single_and_bulk_delete(): void
    {
        $user = $this->userWith('request_logs.delete');
        $a = $this->log();
        $b = $this->log();
        $c = $this->log();

        $this->actingAs($user)->deleteJson(route('request-logs.destroy', $a->id))->assertOk();
        $this->actingAs($user)->postJson(route('request-logs.bulk-delete'), ['ids' => [$b->id]])->assertOk();
        $this->actingAs($user)->postJson(route('request-logs.bulk-delete'), ['ids' => []])->assertUnprocessable();

        $this->assertSame([$c->id], RequestLog::pluck('id')->all());
    }

    public function test_clear_all_requires_super_administrator_even_with_the_permission(): void
    {
        $admin = $this->userWith('request_logs.clear_all');
        $this->log();

        $this->actingAs($admin)->postJson(route('request-logs.clear-all'), ['confirm' => 'DELETE_ALL'])->assertForbidden();
        $this->assertSame(1, RequestLog::count());
    }

    public function test_clear_all_needs_confirmation_and_is_audited_for_super_administrator(): void
    {
        $super = User::factory()->create();
        $super->assignRole('Super Administrator');
        $this->log();
        $this->log();

        $this->actingAs($super)->postJson(route('request-logs.clear-all'))->assertStatus(400);
        $this->assertSame(2, RequestLog::count());

        $this->actingAs($super)->postJson(route('request-logs.clear-all'), ['confirm' => 'DELETE_ALL'])->assertOk();

        $this->assertSame(0, RequestLog::count());
        $audit = AccessAuditLog::where('action', 'request_logs.cleared')->firstOrFail();
        $this->assertSame((string) $super->id, $audit->actor_id);
        $this->assertSame(2, $audit->before['rows']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('request-logs.index'))->assertRedirect(route('login'));
    }
}
