<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O&M assets and maintenance from Guardian's registers: the asset register (om_assets) with its condition and
 * operational status, live equipment status reports, open maintenance work orders and the VMS messages on display.
 * Nothing is estimated; an empty register says so.
 */
class AssetMaintenanceTool implements AeonToolContract
{
    /** "Open" as on the main dashboard's O&M card (OmOperations). */
    private const OPEN_WORK_ORDER = ['pending', 'assigned', 'in_progress'];

    public function __construct(private ToolGate $gate) {}

    public function name(): string
    {
        return 'asset_maintenance';
    }

    public function description(): string
    {
        return 'O&M assets and maintenance: the asset register with condition and operational status, equipment status reports, open maintenance work orders, and the messages currently on the variable message signs (VMS).';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Asset action: "equipment_health", "work_orders", "vms_signs"',
                'enum' => ['equipment_health', 'work_orders', 'vms_signs'],
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $action = (string) ($args['action'] ?? 'equipment_health');

        if ($denied = $this->gate->deny($userId, $action === 'work_orders' ? ['om.maintenance.view'] : ['om.equipment.view'])) {
            return $denied;
        }

        return match ($action) {
            'work_orders' => $this->workOrders(),
            'vms_signs' => $this->vmsSigns(),
            default => $this->equipmentHealth(),
        };
    }

    private function equipmentHealth(): array
    {
        $assets = Schema::hasTable('om_assets')
            ? DB::table('om_assets')->orderBy('asset_code')->get(['asset_code', 'name', 'category', 'start_chainage', 'condition_grade', 'condition_score', 'operational_status', 'last_inspected_at'])
            : collect();
        $equipment = Schema::hasTable('om_equipment_status')
            ? DB::table('om_equipment_status')->orderBy('equipment_code')->get(['equipment_code', 'name', 'location', 'status', 'uptime_pct', 'last_ping_at'])
            : collect();

        if ($assets->isEmpty() && $equipment->isEmpty()) {
            return $this->empty('No assets or equipment are registered yet.');
        }

        $blocks = [];
        if ($assets->isNotEmpty()) {
            $byStatus = $assets->groupBy(fn ($a) => $this->words($a->operational_status))->map->count();
            $blocks[] = ['type' => 'donut', 'title' => 'Registered assets by operational status', 'items' => $byStatus->map(fn ($n, $label) => ['label' => $label, 'value' => $n])->values()->all()];
            $blocks[] = [
                'type' => 'table',
                'columns' => ['Asset', 'Category', 'Chainage', 'Condition', 'Status', 'Last inspected'],
                'rows' => $assets->map(fn ($a) => [
                    trim(($a->asset_code ? $a->asset_code.' · ' : '').($a->name ?? '')),
                    $this->words($a->category),
                    (string) ($a->start_chainage ?: '—'),
                    $a->condition_grade ? $a->condition_grade.($a->condition_score !== null ? " ({$a->condition_score})" : '') : '—',
                    $this->words($a->operational_status),
                    $a->last_inspected_at ? date('d M Y', strtotime((string) $a->last_inspected_at)) : '—',
                ])->all(),
            ];
        }
        if ($equipment->isNotEmpty()) {
            $blocks[] = [
                'type' => 'table',
                'columns' => ['Equipment', 'Location', 'Status', 'Uptime', 'Last report'],
                'rows' => $equipment->map(fn ($e) => [
                    trim(($e->equipment_code ? $e->equipment_code.' · ' : '').($e->name ?? '')),
                    (string) ($e->location ?: '—'),
                    $this->words($e->status),
                    $e->uptime_pct !== null ? $e->uptime_pct.'%' : '—',
                    $e->last_ping_at ? date('d M Y H:i', strtotime((string) $e->last_ping_at)) : '—',
                ])->all(),
            ];
        }

        return [
            'text' => sprintf('%d registered asset%s and %d equipment status report%s.', $assets->count(), $assets->count() === 1 ? '' : 's', $equipment->count(), $equipment->count() === 1 ? '' : 's'),
            'blocks' => $blocks,
            'data' => ['assets' => $assets->count(), 'equipment' => $equipment->count()],
        ];
    }

    private function workOrders(): array
    {
        if (! Schema::hasTable('om_work_orders')) {
            return $this->empty('No work orders are recorded yet.');
        }
        $orders = DB::table('om_work_orders')->whereIn('status', self::OPEN_WORK_ORDER)
            ->orderByRaw("CASE priority WHEN 'emergency' THEN 0 WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->orderBy('target_end_at')->limit(15)->get();

        if ($orders->isEmpty()) {
            return $this->empty('There are no open maintenance work orders.');
        }

        return [
            'text' => $orders->count().' open maintenance work order'.($orders->count() === 1 ? '' : 's').'.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Work order', 'Title', 'Location', 'Priority', 'Status', 'Due'],
                'rows' => $orders->map(fn ($o) => [
                    (string) ($o->work_order_number ?: '#'.$o->id),
                    (string) ($o->title ?: '—'),
                    (string) ($o->location ?: '—'),
                    $this->words($o->priority),
                    $this->words($o->status),
                    $o->target_end_at ? date('d M Y', strtotime((string) $o->target_end_at)) : '—',
                ])->all(),
            ]],
            'data' => ['open_orders' => $orders->count()],
        ];
    }

    private function vmsSigns(): array
    {
        if (! Schema::hasTable('om_vms_messages')) {
            return $this->empty('No VMS messages are recorded yet.');
        }
        $messages = DB::table('om_vms_messages')->where('is_active', true)->orderBy('vms_code')->get();

        if ($messages->isEmpty()) {
            return $this->empty('No message is active on any variable message sign.');
        }

        return [
            'text' => $messages->count().' VMS message'.($messages->count() === 1 ? ' is' : 's are').' on display.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Sign', 'Location', 'Type', 'Message', 'Set at'],
                'rows' => $messages->map(fn ($m) => [
                    (string) $m->vms_code,
                    (string) ($m->location ?: '—'),
                    $this->words($m->type),
                    trim(($m->message_line1 ?? '').' '.($m->message_line2 ?? '')),
                    $m->updated_by_operator_at ? date('d M Y H:i', strtotime((string) $m->updated_by_operator_at)) : '—',
                ])->all(),
            ]],
            'data' => ['active_vms' => $messages->count()],
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
