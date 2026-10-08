<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily works: logged today, still open, awaiting resubmission, emergencies.
 *
 * Permission: daily-works.view. Scope: DepartmentScope - a global role sees every work
 * item; anyone else only items they are in charge of or assigned to, or that belong to
 * employees in their department / reporting line (incharge and assigned are employee ids).
 * One grouped query.
 */
final class DailyWorksSummary extends DashboardWidget
{
    /** Statuses that mean the work is not finished. 'complete' / 'completed' are legacy spellings of done. */
    private const OPEN = [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_PENDING, DailyWork::STATUS_RESUBMISSION, DailyWork::STATUS_EMERGENCY];

    public function key(): string
    {
        return 'project.daily_works';
    }

    public function title(): string
    {
        return 'Daily works';
    }

    public function permissions(): array
    {
        return ['daily-works.view'];
    }

    public function personas(): array
    {
        return ['employee', 'line_manager', 'project_manager', 'administrator'];
    }

    public function section(): string
    {
        return self::SECTION_PROJECT;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 60;
    }

    public function span(): int
    {
        return 12;
    }

    public function ttl(): int
    {
        return 120;
    }

    public function route(User $viewer): ?string
    {
        return 'daily-works-unified';
    }

    public function type(): string
    {
        return 'analytics';
    }

    /** Weeks shown on the weekly chart. */
    private const WEEKS = 8;

    private function scoped(User $viewer): Builder
    {
        $query = DailyWork::query();

        if (! $this->scope->isGlobal($viewer)) {
            $visible = $this->scope->visibleUserIdsQuery($viewer);
            $query->where(fn (Builder $q) => $q->whereIn('incharge', $visible)->orWhereIn('assigned', $visible));
        }

        return $query;
    }

    public function data(User $viewer): array
    {
        $today = now()->toDateString();
        $row = $this->scoped($viewer)->selectRaw(
            'SUM(date = ?) as today, '.
            'SUM(status IN ('.implode(',', array_fill(0, count(self::OPEN), '?')).')) as open_n, '.
            'SUM(status = ?) as resubmission, SUM(status = ?) as emergency',
            [$today, ...self::OPEN, DailyWork::STATUS_RESUBMISSION, DailyWork::STATUS_EMERGENCY],
        )->first();

        $open = (int) ($row->open_n ?? 0);
        $resubmission = (int) ($row->resubmission ?? 0);
        $emergency = (int) ($row->emergency ?? 0);

        return [
            'scope' => $this->scopeMeta($viewer),
            'total' => $open,
            'stats' => [
                $this->stat('today', 'Logged today', (int) ($row->today ?? 0), 'neutral', 'daily-works-unified'),
                $this->stat('open', 'Open', $open, $this->toneFor($open, 'info'), 'daily-works-unified'),
                $this->stat('resubmission', 'Resubmission', $resubmission, $this->toneFor($resubmission), 'daily-works-unified'),
                $this->stat('emergency', 'Emergency', $emergency, $this->toneFor($emergency, 'crit'), 'daily-works-unified'),
            ],
            'charts' => [$this->weekly($viewer)],
        ];
    }

    /** Works logged, completed and sent back per week over the last WEEKS weeks: one grouped query on (date, status). */
    private function weekly(User $viewer): array
    {
        $first = now()->startOfWeek()->subWeeks(self::WEEKS - 1);
        $rows = $this->scoped($viewer)->where('date', '>=', $first->toDateString())
            ->selectRaw('date, status, COUNT(*) as n')->groupBy('date', 'status')->get();

        $logged = $completed = $sentBack = array_fill(0, self::WEEKS, 0);
        foreach ($rows as $r) {
            $index = (int) floor($first->diffInDays(Carbon::parse($r->date)->startOfDay()) / 7);
            if ($index < 0 || $index >= self::WEEKS) {
                continue;
            }
            $logged[$index] += (int) $r->n;
            if (in_array($r->status, ['completed', 'complete'], true)) {
                $completed[$index] += (int) $r->n;
            }
            if (in_array($r->status, [DailyWork::STATUS_RESUBMISSION, DailyWork::STATUS_REJECTED], true)) {
                $sentBack[$index] += (int) $r->n;
            }
        }
        $labels = array_map(fn ($w) => $first->copy()->addWeeks($w)->format('d M'), range(0, self::WEEKS - 1));

        return $this->chart('daily_works_weekly', 'bar', 'Daily works per week',
            'Works logged, completed, and sent back (resubmission or rejected) per week, last '.self::WEEKS.' weeks',
            ['categories' => $labels, 'series' => [
                ['name' => 'Logged', 'tone' => 'theme', 'data' => $logged],
                ['name' => 'Completed', 'tone' => 'good', 'data' => $completed],
                ['name' => 'Resubmission / rejected', 'tone' => 'warn', 'data' => $sentBack],
            ]], ['from' => $first->toDateString(), 'to' => now()->toDateString()], 'No daily works logged in this period.');
    }
}
