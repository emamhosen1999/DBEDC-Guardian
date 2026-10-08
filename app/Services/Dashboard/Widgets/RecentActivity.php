<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\DailyWork;
use App\Models\QualityNCR;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recent project activity: the latest RFIs (daily works) and the newest open NCRs from the IE register, newest first.
 *
 * Permissions: daily-works.view (RFIs) and quality.ncr.view (NCRs); each source appears only for a viewer who holds
 * its permission. RFIs are narrowed through DepartmentScope exactly like the Daily works summary (a global role sees
 * all, anyone else the work they are in charge of or assigned to, or their team's); the NCR register is project-wide
 * and governed by its permission alone, like the page it links to. Never padded: when the registers are quiet the
 * card says when the last activity was.
 */
final class RecentActivity extends DashboardWidget
{
    private const LIMIT = 8;

    public function key(): string
    {
        return 'main.activity';
    }

    public function title(): string
    {
        return 'Recent activity';
    }

    public function permissions(): array
    {
        return ['daily-works.view', 'quality.ncr.view'];
    }

    public function personas(): array
    {
        return ['project_manager', 'line_manager', 'administrator'];
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
        return 41;
    }

    public function span(): int
    {
        return 6;
    }

    public function type(): string
    {
        return 'list';
    }

    public function ttl(): int
    {
        return 120;
    }

    public function route(User $viewer): ?string
    {
        return $viewer->can('daily-works.view') ? 'daily-works-unified' : 'quality.ncr.index';
    }

    public function data(User $viewer): array
    {
        $entries = collect();

        if ($viewer->can('daily-works.view')) {
            $query = DailyWork::query()->whereNotNull('type');
            if (! $this->scope->isGlobal($viewer)) {
                $visible = $this->scope->visibleUserIdsQuery($viewer);
                $query->where(fn (Builder $q) => $q->whereIn('incharge', $visible)->orWhereIn('assigned', $visible));
            }
            foreach ($query->orderByDesc('updated_at')->limit(6)->get(['number', 'type', 'status', 'location', 'side', 'updated_at']) as $r) {
                $done = in_array($r->status, ['completed', 'complete'], true);
                $resubmitted = $r->status === DailyWork::STATUS_RESUBMISSION;
                $entries->push([
                    'at' => $r->updated_at,
                    'item' => $this->item('rfi:'.$r->number,
                        ($done ? 'RFI approved' : ($resubmitted ? 'RFI resubmitted' : 'RFI raised')).' - '.($r->type ?: 'Works').' '.trim(($r->location ?: '').' '.($r->side ?: '')),
                        'RFI #'.($r->number ?: '-'), $r->updated_at?->format('d M Y'), $done ? 'good' : ($resubmitted ? 'warn' : 'info')),
                ]);
            }
        }

        if ($viewer->can('quality.ncr.view')) {
            foreach (QualityNCR::query()->whereIn('status', ['open', 'under_review', 'action_in_progress'])->orderByDesc('detected_date')->limit(3)->get(['ncr_number', 'title', 'severity', 'detected_date']) as $n) {
                $at = $n->detected_date ? Carbon::parse($n->detected_date) : null;
                $entries->push([
                    'at' => $at,
                    'item' => $this->item('ncr:'.$n->ncr_number, $n->ncr_number.' - '.$n->title, ucfirst((string) $n->severity).' NCR', $at?->format('d M Y'), $n->severity === 'critical' ? 'crit' : ($n->severity === 'major' ? 'warn' : 'info')),
                ]);
            }
        }

        $sorted = $entries->sortByDesc(fn (array $e) => $e['at']?->getTimestamp() ?? 0)->values();
        $latest = $sorted->first()['at'] ?? null;

        return [
            'scope' => ['kind' => 'project', 'label' => 'Whole project'],
            'items' => $sorted->take(self::LIMIT)->pluck('item')->all(),
            'last_activity' => $latest?->toDateString(),
            'freshness' => $latest ? 'Last activity: '.$latest->format('j M Y') : 'No activity recorded yet',
        ];
    }
}
