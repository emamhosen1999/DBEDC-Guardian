<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Models\User;
use App\Services\Operations\OmTollAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dhaka Bypass corridor facts from Guardian's own records: the jurisdiction (phase) and in-charge covering a chainage,
 * open incidents, today's toll totals, patrol shifts and an O&M overview. No alignment, landmark or traffic figure is
 * invented; an empty register says so.
 */
class ExpresswayIntelligenceTool implements AeonToolContract
{
    /** "Open" means the same as on the main dashboard's O&M card (OmOperations), so both always agree. */
    private const OPEN_INCIDENT = ['detected', 'dispatched', 'on_scene'];

    private const OPEN_WORK_ORDER = ['pending', 'assigned', 'in_progress'];

    private const OPEN_DEFECT = ['reported', 'investigating', 'work_order_created', 'in_repair'];

    public function __construct(private ToolGate $gate, private OmTollAuditService $tolls) {}

    public function name(): string
    {
        return 'expressway_intelligence';
    }

    public function description(): string
    {
        return 'Dhaka Bypass corridor records: which jurisdiction (phase) and in-charge cover a chainage, open incidents, today\'s toll totals, patrol shifts, and an O&M overview.';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Operation: "chainage_lookup", "active_incidents", "toll_summary", "patrol_status", "overview"',
                'enum' => ['chainage_lookup', 'active_incidents', 'toll_summary', 'patrol_status', 'overview'],
            ],
            'chainage' => [
                'type' => 'string',
                'description' => 'Chainage, e.g. "K14+200", "Ch 14+200" or kilometres "14.2" (for chainage_lookup)',
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $action = (string) ($args['action'] ?? 'overview');

        // Each action mirrors the permission of the page that shows the same records.
        $required = match ($action) {
            'chainage_lookup' => ['daily-works.view', 'om.dashboard.view'],
            'active_incidents' => ['om.incidents.view'],
            'toll_summary' => ['om.toll.manage', 'om.dashboard.view'],
            default => ['om.dashboard.view'],
        };
        if ($denied = $this->gate->deny($userId, $required)) {
            return $denied;
        }

        return match ($action) {
            'chainage_lookup' => $this->lookupChainage((string) ($args['chainage'] ?? '')),
            'active_incidents' => $this->activeIncidents(),
            'toll_summary' => $this->tollSummary(),
            'patrol_status' => $this->patrolStatus(),
            default => $this->overview(),
        };
    }

    /** "K12+500", "Ch 12+500", "12+500" -> 12500 m; "12.5" -> 12500 m; anything else -> null. */
    public static function chainageMeters(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^(?:K|CH\.?)?\s*(\d{1,3})\s*\+\s*(\d{1,3})$/i', $value, $m)) {
            return ((int) $m[1]) * 1000 + (int) $m[2];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d+)?)\s*(?:km)?$/i', $value, $m)) {
            return (int) round(((float) $m[1]) * 1000);
        }

        return null;
    }

    private function lookupChainage(string $chainage): array
    {
        $meters = self::chainageMeters($chainage);
        if ($meters === null) {
            return $this->empty('Give a chainage such as K14+200 or 14.2 km.');
        }
        if (! Schema::hasTable('jurisdictions')) {
            return $this->empty('No jurisdictions are recorded yet.');
        }

        $band = DB::table('jurisdictions')->get()->first(function ($j) use ($meters) {
            $from = self::chainageMeters((string) $j->start_chainage);
            $to = self::chainageMeters((string) $j->end_chainage);

            return $from !== null && $to !== null && $meters >= $from && $meters <= $to;
        });
        $label = sprintf('K%d+%03d', intdiv($meters, 1000), $meters % 1000);

        if ($band === null) {
            return $this->empty("{$label} is outside every recorded jurisdiction.");
        }

        $incharge = $band->incharge ? User::query()->where('employee_id', (string) $band->incharge)->value('name') : null;

        return [
            'text' => "{$label} lies in {$band->location} ({$band->start_chainage} to {$band->end_chainage})".($incharge ? ", in-charge {$incharge}." : '.'),
            'blocks' => [[
                'type' => 'stats',
                'items' => [
                    ['k' => 'Chainage', 'v' => $label],
                    ['k' => 'Jurisdiction', 'v' => (string) $band->location, 'd' => "{$band->start_chainage} to {$band->end_chainage}"],
                    ['k' => 'In-charge', 'v' => $incharge ?? '—'],
                ],
            ]],
            'data' => ['chainage_m' => $meters, 'jurisdiction' => $band->location, 'incharge' => $incharge],
        ];
    }

    private function activeIncidents(): array
    {
        if (! Schema::hasTable('om_incidents')) {
            return $this->empty('No incidents are recorded yet.');
        }
        $incidents = DB::table('om_incidents')->whereIn('status', self::OPEN_INCIDENT)
            ->orderByDesc('reported_at')->limit(10)->get();

        if ($incidents->isEmpty()) {
            return $this->empty('There are no open incidents.');
        }

        return [
            'text' => $incidents->count().' open incident'.($incidents->count() === 1 ? '' : 's').' on the corridor.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Incident', 'Chainage', 'Type', 'Severity', 'Status', 'Reported'],
                'rows' => $incidents->map(fn ($r) => [
                    (string) ($r->incident_number ?: $r->title ?: '#'.$r->id),
                    (string) ($r->chainage ?: '—'),
                    $this->words($r->incident_type),
                    $this->words($r->severity),
                    $this->words($r->status),
                    $r->reported_at ? date('d M Y H:i', strtotime((string) $r->reported_at)) : '—',
                ])->all(),
            ]],
            'data' => ['open_count' => $incidents->count()],
        ];
    }

    private function tollSummary(): array
    {
        $toll = $this->tolls->getTollSummary();
        $transactions = (int) ($toll['total_transactions_today'] ?? 0);

        if ($transactions === 0) {
            return $this->empty('No toll transactions have been recorded today.');
        }

        return [
            'text' => sprintf('Toll today: %s transactions, ৳ %s collected, %s%% electronic (ETC).', number_format($transactions), number_format((float) $toll['total_revenue_today']), $toll['etc_percentage']),
            'blocks' => [[
                'type' => 'stats',
                'items' => [
                    ['k' => 'Transactions today', 'v' => number_format($transactions)],
                    ['k' => 'Revenue today', 'v' => '৳ '.number_format((float) $toll['total_revenue_today'])],
                    ['k' => 'Electronic (ETC)', 'v' => $toll['etc_percentage'].'%', 'd' => $toll['cash_percentage'].'% cash'],
                    ['k' => 'Unresolved shift audits', 'v' => (string) ($toll['discrepancy_audits_count'] ?? 0)],
                ],
            ]],
            'data' => $toll,
        ];
    }

    private function patrolStatus(): array
    {
        if (! Schema::hasTable('om_patrol_shifts')) {
            return $this->empty('No patrol shifts are recorded yet.');
        }
        $today = now()->toDateString();
        $shifts = DB::table('om_patrol_shifts')
            ->where(fn ($q) => $q->where('patrol_date', $today)->orWhere('status', 'in_progress'))
            ->orderByDesc('patrol_date')->orderBy('id')->get();

        if ($shifts->isEmpty()) {
            return $this->empty('No patrol shift is scheduled or running today.');
        }

        return [
            'text' => $shifts->count().' patrol shift'.($shifts->count() === 1 ? '' : 's').' today or still running.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Patrol', 'Date', 'Shift', 'Vehicle', 'Zone', 'Status'],
                'rows' => $shifts->map(fn ($s) => [
                    (string) ($s->call_sign ?: $s->patrol_code ?: '#'.$s->id),
                    (string) $s->patrol_date,
                    $this->words($s->shift_type),
                    (string) ($s->vehicle_reg_number ?: '—'),
                    trim(($s->assigned_zone_from ?? '').' to '.($s->assigned_zone_to ?? ''), ' to') ?: '—',
                    $this->words($s->status),
                ])->all(),
            ]],
            'data' => ['shifts' => $shifts->count()],
        ];
    }

    private function overview(): array
    {
        $count = fn (string $table, ?callable $where = null): ?int => Schema::hasTable($table)
            ? (int) ($where ? $where(DB::table($table)) : DB::table($table))->count()
            : null;

        $figures = array_filter([
            'Jurisdictions' => $count('jurisdictions'),
            'Registered assets' => $count('om_assets'),
            'Open defects' => $count('om_defects', fn ($q) => $q->whereIn('status', self::OPEN_DEFECT)),
            'Open incidents' => $count('om_incidents', fn ($q) => $q->whereIn('status', self::OPEN_INCIDENT)),
            'Open work orders' => $count('om_work_orders', fn ($q) => $q->whereIn('status', self::OPEN_WORK_ORDER)),
            'Patrols today' => $count('om_patrol_shifts', fn ($q) => $q->where('patrol_date', now()->toDateString())),
        ], fn ($v) => $v !== null);

        return [
            'text' => 'Corridor O&M records: '.collect($figures)->map(fn ($v, $k) => "{$k} {$v}")->implode(', ').'.',
            'blocks' => [[
                'type' => 'stats',
                'items' => collect($figures)->map(fn ($v, $k) => ['k' => $k, 'v' => (string) $v])->values()->all(),
            ]],
            'data' => $figures,
        ];
    }

    private function words(?string $value): string
    {
        return $value ? ucfirst(str_replace('_', ' ', $value)) : '—';
    }

    /** @return array{text: string, blocks: array<int, mixed>, data: array<string, mixed>} */
    private function empty(string $message): array
    {
        return ['text' => $message, 'blocks' => [], 'data' => ['empty' => true]];
    }
}
