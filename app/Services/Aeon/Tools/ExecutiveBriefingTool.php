<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\WidgetRegistry;
use Illuminate\Support\Carbon;

/**
 * Leadership briefing built from the main dashboard's own widgets: the same figures, the same permission per widget,
 * the same department scope and the same cache. A pillar the viewer may not see is left out, never estimated, and
 * every line says whose data it is and when it was computed.
 */
class ExecutiveBriefingTool implements AeonToolContract
{
    /** Main-dashboard widgets that make up the briefing, in reading order. */
    public const PILLARS = [
        'team.today' => 'Workforce today',
        'team.approvals' => 'Pending approvals',
        'ops.om' => 'Operations and maintenance',
        'project.ncr' => 'Quality (NCRs)',
        'project.daily_works' => 'Daily works',
        'hr.snapshot' => 'HR',
        'admin.system_health' => 'System health',
    ];

    public function __construct(private ToolGate $gate, private WidgetRegistry $widgets) {}

    public function name(): string
    {
        return 'executive_briefing';
    }

    public function description(): string
    {
        return 'Leadership briefing of what you are allowed to see right now: workforce today, pending approvals, operations and maintenance, quality (NCRs), daily works, HR and system health - the live figures behind the main dashboard.';
    }

    public function parameters(): array
    {
        return [];
    }

    /** Offered only to people who can see at least one pillar (ToolRegistry). */
    public function availableTo(?User $actor): bool
    {
        return $actor !== null && $this->widgets->visibleFor($actor, DashboardWidget::DASHBOARD_MAIN)
            ->contains(fn (DashboardWidget $widget): bool => isset(self::PILLARS[$widget->key()]));
    }

    public function run(array $args, int|string|null $userId): array
    {
        $actor = $this->gate->actor($userId);
        if (! $this->availableTo($actor)) {
            return ['text' => 'You do not have access to this information.', 'blocks' => [], 'data' => ['error' => 'forbidden']];
        }

        $rows = [];
        $lines = [];
        $data = [];
        foreach (self::PILLARS as $key => $label) {
            $widget = $this->widgets->widgetFor($actor, $key);
            if ($widget === null || $widget['error'] !== null || ! is_array($widget['data'])) {
                continue;
            }
            $stats = collect($widget['data']['stats'] ?? [])->filter(fn ($s) => is_array($s) && isset($s['label']));
            if ($stats->isEmpty()) {
                continue;
            }
            $figures = $stats->map(fn (array $s) => $s['label'].': '.($s['value'] ?? '—'))->implode(', ');
            $scope = $widget['data']['scope']['label'] ?? null;
            $asOf = $this->clock($widget['as_of'] ?? null);
            $freshness = $widget['data']['freshness'] ?? null;

            $rows[] = [$label, $figures, $scope ?? '—', trim(($freshness ? $freshness.' · ' : '').($asOf ? 'updated '.$asOf : ''), ' ·') ?: '—'];
            $lines[] = "- **{$label}**".($scope ? " ({$scope})" : '').": {$figures}.";
            $data[$key] = $stats->mapWithKeys(fn (array $s) => [(string) ($s['key'] ?? $s['label']) => $s['value'] ?? null])->all();
        }

        if ($rows === []) {
            return ['text' => 'None of the briefing figures are available right now.', 'blocks' => [], 'data' => ['pillars' => 0]];
        }

        return [
            'text' => '### Operational briefing — '.now()->format('d M Y, H:i')."\n\n".implode("\n", $lines),
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Area', 'Figures', 'Scope', 'Freshness'],
                'rows' => $rows,
            ]],
            'data' => ['pillars' => $data, 'generated_at' => now()->toIso8601String()],
        ];
    }

    private function clock(?string $iso): ?string
    {
        if (! $iso) {
            return null;
        }
        try {
            return Carbon::parse($iso)->timezone(config('app.timezone'))->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
