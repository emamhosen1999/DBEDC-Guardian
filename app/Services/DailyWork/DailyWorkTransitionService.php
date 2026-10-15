<?php

namespace App\Services\DailyWork;

use App\Events\Domain\DailyWorkStatusChanged;
use App\Models\DailyWork;
use App\Models\User;
use App\Services\Concurrency\VersionGuard;
use App\Services\DailyWork\History\DailyWorkChangeContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A status transition as ONE action, enforced on the server: the allowed-transition map, the permission (re-opening a
 * closed RFI is its own), the version check, and everything a transition implies - completing records the time and needs
 * the inspection result, a resubmission is the next numbered revision of the SAME work, going back to new clears the
 * completion. Who and when is written to the history by the model observer (revision + status event).
 */
class DailyWorkTransitionService
{
    public function transition(DailyWork $work, string $to, ?string $inspectionResult, ?string $reason, ?int $expectedVersion, User $actor): DailyWork
    {
        if (! DailyWork::isValidStatus($to) || $to === null) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }

        return DB::transaction(function () use ($work, $to, $inspectionResult, $reason, $expectedVersion, $actor): DailyWork {
            $locked = DailyWork::query()->lockForUpdate()->findOrFail($work->getKey());
            VersionGuard::assertMatches($locked, $expectedVersion);
            $from = DailyWorkWorkflow::normalise($locked->status);

            if ($from === $to) {
                throw ValidationException::withMessages(['status' => "The RFI is already {$to}."]);
            }
            if (! in_array($to, DailyWorkWorkflow::targets($from, $locked->inspection_result, $actor->can('daily-works.status.reopen')), true)) {
                DailyWorkWorkflow::assertAllowed($from, $to, $locked->inspection_result, $actor); // throws with the reason
            }
            if ($to === DailyWork::STATUS_COMPLETED && ! in_array($inspectionResult, [DailyWork::INSPECTION_PASS, DailyWork::INSPECTION_FAIL], true)) {
                throw ValidationException::withMessages(['inspection_result' => 'Completing an RFI needs the inspection result: pass or fail.']);
            }
            if ($to === DailyWork::STATUS_REJECTED && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages(['reason' => 'Say why the RFI is rejected.']);
            }

            $attrs = ['status' => $to];
            switch ($to) {
                case DailyWork::STATUS_COMPLETED:
                    $attrs['inspection_result'] = $inspectionResult;
                    $attrs['completion_time'] = $locked->completion_time ?? now();
                    break;
                case DailyWork::STATUS_NEW:
                    $attrs['inspection_result'] = null;
                    $attrs['completion_time'] = null;
                    break;
                case DailyWork::STATUS_RESUBMISSION:
                    $count = (int) $locked->resubmission_count + 1;
                    $attrs['resubmission_count'] = $count;
                    $attrs['resubmission_date'] = $this->ordinal($count).' Resubmission on '.Carbon::now()->format('jS F Y');
                    $attrs['inspection_result'] = null;
                    $attrs['completion_time'] = null;
                    break;
                default:
                    if ($inspectionResult !== null) {
                        $attrs['inspection_result'] = $inspectionResult;
                    }
            }

            $locked->fill($attrs);
            $locked->lock_version = VersionGuard::next($locked);
            DailyWorkChangeContext::with(DailyWorkChangeContext::source(), DailyWorkChangeContext::batchId(), fn () => $locked->save(), $reason, (string) $actor->employee_id);

            DailyWorkStatusChanged::dispatch($actor->id, $locked->id, $from, $to);

            return $locked->fresh(['inchargeUser:employee_id,name', 'assignedUser:employee_id,name']) ?? $locked;
        });
    }

    private function ordinal(int $n): string
    {
        if (in_array($n % 100, [11, 12, 13], true)) {
            return $n.'th';
        }

        return $n.(['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10]);
    }
}
