<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access\CatalogApplier;
use App\Services\Access\CatalogPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Apply the approved role-catalog assignment plan (docs/audit/ROLE_CATALOG_2026-10-03.md, A3).
 *
 *   access:apply-catalog                      dry-run (the default): per-user diff and the plan hash
 *   access:apply-catalog --backup             write the backup for this plan hash
 *   access:apply-catalog --apply --actor=151 --reason="..." --plan-hash=<hash>
 *   access:apply-catalog --restore=<backup directory> --actor=151 --reason="..."
 *
 * The RBAC tables are MyISAM in production: nothing here relies on a rollback. The plan is absolute, so
 * re-running converges; --apply refuses without a complete backup of the SAME plan hash; the result
 * is verified afterwards and any drift exits non-zero.
 */
class ApplyAccessCatalog extends Command
{
    protected $signature = 'access:apply-catalog
        {--plan= : Path to the plan JSON (default: database/access-plans/catalog_v1.json)}
        {--dry-run : Show what would change and the plan hash (the default)}
        {--backup : Write the backup for this plan hash before anything else}
        {--apply : Apply the plan (needs --actor, --reason, --plan-hash and a backup of the same hash)}
        {--actor= : Employee ID of the Super Administrator running the change}
        {--reason= : Why (recorded in the access audit ledger)}
        {--plan-hash= : The hash the dry-run printed; the apply refuses any other plan}
        {--restore= : Re-apply a backup directory through the same path}';

    protected $description = 'Dry-run, back up, apply, verify or restore the role catalog assignment plan';

    public function handle(CatalogApplier $applier): int
    {
        try {
            if ($this->option('restore')) {
                return $this->restore($applier);
            }

            $plan = CatalogPlan::load($this->option('plan') ?: null);
            $report = $applier->plan($plan);
            $this->printReport($report);

            if ($report['errors'] !== []) {
                $this->error(count($report['errors']).' error(s): nothing was written.');

                return self::FAILURE;
            }

            if ($this->option('backup') || $this->option('apply')) {
                $existing = $this->option('backup') ? null : $applier->findBackup($plan);
                if ($this->option('backup') || $existing === null) {
                    if (! $this->option('backup')) {
                        $this->error('Refusing to apply: there is no complete backup for plan hash '.$plan->hash().'. Run with --backup first.');

                        return self::FAILURE;
                    }
                    $directory = $applier->backup($plan);
                    $this->info("Backup written: {$directory}");
                } else {
                    $this->info("Backup found: {$existing}");
                }
            }

            if (! $this->option('apply')) {
                if (! $this->option('backup')) {
                    $this->comment('Dry-run only. Nothing was written.');
                }

                return self::SUCCESS;
            }

            return $this->apply($applier, $plan);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);

            return self::FAILURE;
        }
    }

    private function apply(CatalogApplier $applier, CatalogPlan $plan): int
    {
        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required: the change must be documented.');

            return self::INVALID;
        }
        if ($this->option('plan-hash') !== $plan->hash()) {
            $this->error('--plan-hash does not match the plan on disk ('.$plan->hash().'). Run the dry-run again and review it.');

            return self::FAILURE;
        }
        if ($applier->findBackup($plan) === null) {
            $this->error('Refusing to apply: no complete backup for this plan hash.');

            return self::FAILURE;
        }

        $stats = $applier->apply($plan, $actor->employee_id, $reason);
        $this->table(['Step', 'Count'], collect($stats)->map(fn ($n, $k) => [$k, $n])->values()->all());

        $drift = $applier->verify($plan);
        if ($drift !== []) {
            foreach ($drift as $line) {
                $this->error("DRIFT: {$line}");
            }
            Log::critical('access:apply-catalog: verification found drift', ['plan_hash' => $plan->hash(), 'drift' => $drift]);
            $this->error('Verification FAILED. Re-run --apply (it converges) or --restore from the backup.');

            return self::FAILURE;
        }

        $this->info('Applied and verified: the database equals the plan ('.$plan->hash().').');

        return self::SUCCESS;
    }

    private function restore(CatalogApplier $applier): int
    {
        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required.');

            return self::INVALID;
        }

        $stats = $applier->restore((string) $this->option('restore'), $actor->employee_id, $reason);
        $this->table(['Step', 'Count'], collect($stats)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info('Backup restored.');

        return self::SUCCESS;
    }

    /** The acting Super Administrator (a change of access is only ever made by one). */
    private function resolveActor(): ?User
    {
        $actor = User::query()->find((string) $this->option('actor'));
        if ($actor === null || ! $actor->hasRole('Super Administrator')) {
            $this->error('--actor must be the employee ID of an active Super Administrator.');

            return null;
        }

        return $actor;
    }

    /** @param array<string, mixed> $report */
    private function printReport(array $report): void
    {
        $rows = [];
        foreach ($report['users'] as $id => $user) {
            $change = array_filter([
                $user['roles_add'] ? '+'.implode(' +', $user['roles_add']) : null,
                $user['roles_remove'] ? '-'.implode(' -', $user['roles_remove']) : null,
                $user['direct_remove'] ? 'direct -'.implode(' -', $user['direct_remove']) : null,
                $user['direct_add'] ? 'direct +'.implode(' +', $user['direct_add']) : null,
            ]);
            $rows[] = [$id, $user['name'], $change ? implode('  ', $change) : '(unchanged)', count($user['lost'])];
        }
        $this->table(['ID', 'Name', 'Role / direct-permission changes', 'Perms lost now'], $rows);

        foreach ($report['defaults'] as $departmentId => $default) {
            $this->line("Department {$departmentId} default roles: [".implode(', ', $default['current']).'] -> ['.implode(', ', $default['target']).']'.($default['changes'] ? '' : ' (unchanged)'));
        }
        foreach ($report['orphans'] as $role => $count) {
            $this->line("Orphan role rows to detach: {$count} x {$role}");
        }
        foreach ($report['deletable'] as $role => $holders) {
            $this->line($holders === null ? "Role {$role}: already gone" : "Role {$role}: deleted once it has no holder (holders after the plan: {$holders})");
        }
        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }
        foreach ($report['errors'] as $error) {
            $this->error($error);
        }
        $this->line('Plan hash: '.$report['plan_hash']);
    }
}
