<?php

namespace App\Services\Approvals;

use App\Models\CompanySetting;
use App\Models\HRM\Department;
use App\Models\User;
use App\Notifications\Approvals\ApprovalRoutingIncompleteNotification;
use App\Services\Notification\NotificationRecipients;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * The ONE place that decides who approves a request: leave, overtime and regularization all route through
 * here (supervisory-organization model).
 *
 *   manager()        the requester's line manager (users.report_to) when usable
 *   departmentHead() the head of the requester's HOME department (departments.manager_id)
 *   escalation()     for anyone without a usable manager: (a) HR Manager role holders, (b) the configured
 *                    escalation approver (company setting), (c) only then Super Administrators, which also
 *                    tells the Super Administrators that routing is incomplete.
 *
 * "Usable" = exists (soft-deleted users do not), is active, and is not the requester: a request never
 * routes to its requester.
 */
class ApprovalRouting
{
    public const HR_ROLE = 'HR Manager';

    public const LAST_RESORT_ROLE = 'Super Administrator';

    public function manager(User $requester): ?User
    {
        $id = $requester->report_to ?? $requester->report_to_id ?? null;

        return $this->usable($id !== null && $id !== '' ? User::find($id) : null, $requester);
    }

    public function departmentHead(User $requester): ?User
    {
        if (! $requester->department_id) {
            return null;
        }
        $headId = Department::query()->whereKey($requester->department_id)->value('manager_id');

        return $this->usable($headId ? User::find($headId) : null, $requester);
    }

    /**
     * @return array{user: User, source: string}|null source: 'hr' | 'configured' | 'super_admin'
     */
    public function escalation(User $requester, bool $notify = true): ?array
    {
        $hr = $this->roleHolder([self::HR_ROLE], $requester);
        if ($hr) {
            return ['user' => $hr, 'source' => 'hr'];
        }

        $configured = $this->usable($this->configuredEscalationApprover(), $requester);
        if ($configured) {
            return ['user' => $configured, 'source' => 'configured'];
        }

        $last = $this->roleHolder([self::LAST_RESORT_ROLE], $requester);
        if ($last) {
            if ($notify) {
                $this->announceIncompleteRouting($requester, $last);
            }

            return ['user' => $last, 'source' => 'super_admin'];
        }

        return null;
    }

    public function escalationApprover(User $requester, bool $notify = true): ?User
    {
        return $this->escalation($requester, $notify)['user'] ?? null;
    }

    /** Does an escalation approver of step (a) or (b) exist at all, independent of any requester? */
    public function hasEscalationApprover(): bool
    {
        return $this->roleHolder([self::HR_ROLE], null) !== null
            || $this->usable($this->configuredEscalationApprover(), null) !== null;
    }

    public function configuredEscalationApprover(): ?User
    {
        $id = $this->configuredEscalationApproverId();

        return $id ? User::find($id) : null;
    }

    public function configuredEscalationApproverId(): ?string
    {
        if (! Schema::hasTable('company_settings') || ! Schema::hasColumn('company_settings', 'escalation_approver_id')) {
            return null;
        }
        $id = CompanySetting::query()->value('escalation_approver_id');

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    private function usable(?User $candidate, ?User $requester): ?User
    {
        // users.is_active carries no boolean cast: NULL counts as active, 0/'0'/false as inactive.
        if (! $candidate || ($candidate->is_active !== null && ! (bool) $candidate->is_active)) {
            return null;
        }
        if ($requester && (string) $candidate->employee_id === (string) $requester->employee_id) {
            return null;
        }

        return $candidate;
    }

    /** @param  array<int, string>  $roles */
    private function roleHolder(array $roles, ?User $requester): ?User
    {
        // User::role() throws for an unknown role name; a missing one simply has no holders.
        $existing = Role::query()->whereIn('name', $roles)->pluck('name')->all();
        if ($existing === []) {
            return null;
        }

        return User::role($existing)
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->when($requester, fn ($q) => $q->where('employee_id', '!=', $requester->employee_id))
            ->orderBy('employee_id')
            ->first();
    }

    private function announceIncompleteRouting(User $requester, User $routedTo): void
    {
        try {
            $recipients = app(NotificationRecipients::class)->globalAdmins($requester)
                ->filter(fn (User $u) => $u->hasRole(self::LAST_RESORT_ROLE));
            if ($recipients->isEmpty()) {
                return;
            }
            Notification::send($recipients, (new ApprovalRoutingIncompleteNotification(
                (string) $requester->name,
                (string) $requester->employee_id,
                (string) $routedTo->name,
            ))->afterCommit());
        } catch (\Throwable $e) {
            Log::error('Approval routing: incomplete-routing notice failed', ['error' => $e->getMessage()]);
        }
    }
}
