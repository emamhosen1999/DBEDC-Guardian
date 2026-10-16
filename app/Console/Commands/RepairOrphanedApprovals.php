<?php

namespace App\Console\Commands;

use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\ReplacementLeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A pending request whose current approver does not exist (a mistyped id, a removed account) can be decided by
 * nobody. Overtime, regularization and replacement requests have a single approver, so they are re-routed through
 * the normal rule (line manager, else the escalation approver). Leaves have multi-level chains: they are only
 * reported. Report-only unless --apply.
 */
class RepairOrphanedApprovals extends Command
{
    protected $signature = 'approvals:repair-orphaned {--apply : Re-route the requests (default: report only)}';

    protected $description = 'Re-route pending overtime, regularization and replacement requests whose current approver does not exist';

    public function handle(AttendanceApprovalService $approvals): int
    {
        $apply = (bool) $this->option('apply');
        $rerouted = 0;
        $models = ['overtime' => OvertimeRequest::class, 'regularization' => AttendanceRegularization::class, 'replacement' => ReplacementLeaveRequest::class];

        foreach ($models as $label => $class) {
            foreach ($class::query()->where('status', 'pending')->get() as $request) {
                $missing = $this->missingApprover($request);
                if ($missing === null) {
                    continue;
                }
                $requester = User::find($request->user_id);
                $chain = $requester ? $approvals->buildChain($requester) : [];
                $to = $chain[0]['approver_id'] ?? null;
                $this->line(sprintf('%s #%d (employee %s): approver %s does not exist -> %s', $label, $request->id, $request->user_id, $missing, $to ?? 'any global approver'));
                if ($apply) {
                    $request->update(['approval_chain' => $chain, 'current_approval_level' => $chain === [] ? 0 : 1]);
                    Log::info('approvals.rerouted', ['type' => $label, 'id' => $request->id, 'from' => $missing, 'to' => $to]);
                }
                $rerouted++;
            }
        }

        foreach (Leave::query()->where('status', 'pending')->get() as $leave) {
            if (($missing = $this->missingApprover($leave)) !== null) {
                $this->warn("leave #{$leave->id} (employee {$leave->user_id}): approver {$missing} does not exist - re-route it by hand");
            }
        }

        $this->info($rerouted === 0 ? 'No orphaned approvals.' : ($apply ? "Re-routed {$rerouted}." : "{$rerouted} to re-route; run with --apply."));

        return self::SUCCESS;
    }

    /** The current level's approver id when that person does not exist, else null. */
    private function missingApprover($request): ?string
    {
        $current = collect((array) $request->approval_chain)->firstWhere('level', (int) $request->current_approval_level);
        $id = $current['approver_id'] ?? null;

        return $id !== null && $id !== '' && ! User::whereKey((string) $id)->exists() ? (string) $id : null;
    }
}
