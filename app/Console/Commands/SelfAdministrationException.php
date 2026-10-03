<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access\SelfAdministration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Self-administration is a documented segregation-of-duties exception (ISO 27001 A.5.3), so it is
 * granted to ONE named person with a reason, never through a role. Every grant and revocation lands
 * in that person's self-administration ledger and is announced to the global admins.
 */
class SelfAdministrationException extends Command
{
    protected $signature = 'access:self-administration
        {action : grant, revoke or list}
        {employee? : The employee ID (grant and revoke)}
        {--reason= : Why this person needs the exception (required to grant)}';

    protected $description = 'Grant, revoke or list the per-person self-administration exception';

    public function handle(SelfAdministration $selfAdministration): int
    {
        $action = strtolower((string) $this->argument('action'));

        if ($action === 'list') {
            $holders = User::permission(SelfAdministration::PERMISSION)->orderBy('name')->get(['employee_id', 'name', 'department_id']);
            $this->table(['Employee ID', 'Name', 'Department ID'], $holders->map(fn (User $u) => [$u->employee_id, $u->name, $u->department_id])->all());

            return self::SUCCESS;
        }

        if (! in_array($action, ['grant', 'revoke'], true)) {
            $this->error('The action must be grant, revoke or list.');

            return self::INVALID;
        }

        $user = User::find((string) $this->argument('employee'));
        if ($user === null) {
            $this->error('No active employee has that ID.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($action === 'grant' && $reason === '') {
            $this->error('A grant needs --reason: the exception must be documented.');

            return self::INVALID;
        }

        $holds = $user->hasDirectPermission(SelfAdministration::PERMISSION);
        if (($action === 'grant') === $holds) {
            $this->info("{$user->name} ({$user->employee_id}) ".($holds ? 'already holds' : 'does not hold').' the exception; nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($action, $user, $reason, $selfAdministration) {
            $action === 'grant'
                ? $user->givePermissionTo(SelfAdministration::PERMISSION)
                : $user->revokePermissionTo(SelfAdministration::PERMISSION);

            $selfAdministration->record(
                $user,
                'exception.'.($action === 'grant' ? 'granted' : 'revoked'),
                ($action === 'grant' ? 'was granted' : 'no longer holds').' the self-administration exception'.($reason !== '' ? " ({$reason})" : ''),
                'user',
                $user->employee_id,
                array_filter(['reason' => $reason, 'by' => 'console']),
            );
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("{$user->name} ({$user->employee_id}): self-administration ".($action === 'grant' ? 'granted' : 'revoked').'.');

        return self::SUCCESS;
    }
}
