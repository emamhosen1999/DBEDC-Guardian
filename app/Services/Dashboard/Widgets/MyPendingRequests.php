<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;

/**
 * Requests the viewer has open, and swap requests waiting for THEIR reply. Own records
 * only. Each figure appears only when the viewer can open the page that explains it.
 */
final class MyPendingRequests extends DashboardWidget
{
    public function key(): string
    {
        return 'me.pending_requests';
    }

    public function title(): string
    {
        return 'My pending requests';
    }

    public function permissions(): array
    {
        return ['leave.own.view', 'attendance.own.view'];
    }

    public function personas(): array
    {
        return ['employee', 'line_manager', 'department_admin', 'hr_manager'];
    }

    public function section(): string
    {
        return self::SECTION_ME;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_EMPLOYEE;
    }

    public function priority(): int
    {
        return 15;
    }

    public function span(): int
    {
        return 6;
    }

    public function ttl(): int
    {
        return 60;
    }

    public function route(User $viewer): ?string
    {
        return $viewer->can('attendance.own.view') ? 'attendance-employee' : 'leaves-employee';
    }

    public function mobileRoute(): ?string
    {
        return '/my-requests';
    }

    public function data(User $viewer): array
    {
        $me = $this->id($viewer);
        $stats = [];

        if ($viewer->can('leave.own.view')) {
            $leaves = Leave::query()->where('user_id', $me)->whereRaw('LOWER(status) IN (?, ?)', ['new', 'pending'])->count();
            $stats[] = $this->stat('leaves', 'Leave requests', $leaves, $this->toneFor($leaves), 'leaves-employee', '/leaves');
        }

        if ($viewer->can('attendance.own.view')) {
            $regularizations = AttendanceRegularization::query()->where('user_id', $me)->where('status', 'pending')->count();
            $overtime = OvertimeRequest::query()->where('user_id', $me)->where('status', 'pending')->count();
            $swaps = ShiftSwapRequest::query()->where('requester_id', $me)->where('status', 'pending')->count();
            $awaitingMe = ShiftSwapRequest::query()
                ->where('counterparty_id', $me)
                ->where('status', 'pending')
                ->where('counterparty_status', 'pending')
                ->count();

            $stats[] = $this->stat('regularizations', 'Regularizations', $regularizations, $this->toneFor($regularizations), 'attendance-employee', '/my-requests');
            $stats[] = $this->stat('overtime', 'Overtime requests', $overtime, $this->toneFor($overtime), 'attendance-employee', '/my-requests');
            $stats[] = $this->stat('swaps', 'Shift swaps', $swaps, $this->toneFor($swaps), 'attendance-employee', '/swaps');
            $stats[] = $this->stat('swaps_awaiting_me', 'Swaps awaiting your reply', $awaitingMe, $this->toneFor($awaitingMe, 'crit'), 'attendance-employee', '/swaps');
        }

        $total = array_sum(array_map(fn (array $stat) => (int) $stat['value'], $stats));

        return [
            'total' => $total,
            'stats' => $stats,
            'kpis' => [$this->kpi('open_requests', 'Open requests', $total, $this->toneFor($total), 'attendance-employee', null, null, null, $total > 0 ? 'waiting for a decision' : 'nothing waiting')],
            'items' => $this->timeline($viewer),
        ];
    }

    /**
     * My requests of the last 90 days as a timeline, newest first: leave, regularization, overtime and shift swap with
     * their decision status. Four small queries, merged in memory.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(User $viewer): array
    {
        $me = $this->id($viewer);
        $since = now()->subDays(90);
        $entries = collect();

        if ($viewer->can('leave.own.view')) {
            foreach (Leave::query()->where('user_id', $me)->where('created_at', '>=', $since)->latest()->limit(8)->get(['id', 'from_date', 'to_date', 'status', 'created_at']) as $l) {
                $entries->push(['at' => $l->created_at, 'title' => 'Leave '.Carbon::parse($l->from_date)->format('d M').' - '.Carbon::parse($l->to_date)->format('d M'), 'status' => strtolower((string) $l->status), 'id' => 'leave:'.$l->id]);
            }
        }

        if ($viewer->can('attendance.own.view')) {
            foreach (AttendanceRegularization::query()->where('user_id', $me)->where('created_at', '>=', $since)->latest()->limit(8)->get(['id', 'date', 'status', 'created_at']) as $r) {
                $entries->push(['at' => $r->created_at, 'title' => 'Regularization '.Carbon::parse($r->date)->format('d M'), 'status' => strtolower((string) $r->status), 'id' => 'reg:'.$r->id]);
            }
            foreach (OvertimeRequest::query()->where('user_id', $me)->where('created_at', '>=', $since)->latest()->limit(8)->get(['id', 'date', 'status', 'created_at']) as $o) {
                $entries->push(['at' => $o->created_at, 'title' => 'Overtime '.Carbon::parse($o->date)->format('d M'), 'status' => strtolower((string) $o->status), 'id' => 'ot:'.$o->id]);
            }
            foreach (ShiftSwapRequest::query()->where('requester_id', $me)->where('created_at', '>=', $since)->latest()->limit(8)->get(['id', 'requester_date', 'status', 'created_at']) as $w) {
                $entries->push(['at' => $w->created_at, 'title' => 'Shift swap '.Carbon::parse($w->requester_date)->format('d M'), 'status' => strtolower((string) $w->status), 'id' => 'swap:'.$w->id]);
            }
        }

        $tones = ['approved' => 'good', 'accepted' => 'good', 'pending' => 'warn', 'new' => 'warn', 'rejected' => 'crit', 'cancelled' => 'neutral'];

        return $entries->sortByDesc('at')->take(8)->map(fn (array $e) => $this->item(
            $e['id'], $e['title'], ucfirst($e['status']), $e['at']->format('d M'), $tones[$e['status']] ?? 'neutral',
        ))->values()->all();
    }
}
