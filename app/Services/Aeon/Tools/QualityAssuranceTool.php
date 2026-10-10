<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Models\DailyWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality registers from Guardian: non-conformance reports (quality_ncrs), RFIs (daily_works and their inspection
 * results), RFI objections and the engineer's site instructions. Counts use the same definitions as the main
 * dashboard's cards; each answer names the latest entry so stale registers are visible. Nothing is invented.
 */
class QualityAssuranceTool implements AeonToolContract
{
    /** Closed NCRs, as on the dashboard's NCR card (QualityNcrSummary): everything else is open. */
    private const NCR_CLOSED = ['closed', 'verified'];

    /** Open RFIs, as on the dashboard's daily works card (DailyWorksSummary). */
    private const RFI_OPEN = [DailyWork::STATUS_NEW, DailyWork::STATUS_IN_PROGRESS, DailyWork::STATUS_PENDING, DailyWork::STATUS_RESUBMISSION, DailyWork::STATUS_EMERGENCY];

    private const LIST_LIMIT = 10;

    public function __construct(private ToolGate $gate) {}

    public function name(): string
    {
        return 'quality_assurance';
    }

    public function description(): string
    {
        return 'Quality registers: non-conformance reports (NCRs), RFI status and inspection results, RFI objections, and site instructions.';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'QA action: "ncr_summary", "rfi_status", "objections_breakdown", "site_instructions"',
                'enum' => ['ncr_summary', 'rfi_status', 'objections_breakdown', 'site_instructions'],
            ],
            'status' => [
                'type' => 'string',
                'description' => 'For NCR and site-instruction lists: "open" (default), "closed" or "all"',
                'enum' => ['open', 'closed', 'all'],
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $action = (string) ($args['action'] ?? 'ncr_summary');
        $status = in_array($args['status'] ?? null, ['open', 'closed', 'all'], true) ? $args['status'] : 'open';

        // NCRs sit behind quality.ncr.view; RFIs, objections and site instructions behind daily-works.view.
        $required = in_array($action, ['rfi_status', 'objections_breakdown', 'site_instructions'], true) ? ['daily-works.view'] : ['quality.ncr.view'];
        if ($denied = $this->gate->deny($userId, $required)) {
            return $denied;
        }

        return match ($action) {
            'rfi_status' => $this->rfiStatus(),
            'objections_breakdown' => $this->objections(),
            'site_instructions' => $this->siteInstructions($status),
            default => $this->ncrSummary($status),
        };
    }

    private function ncrSummary(string $status): array
    {
        if (! Schema::hasTable('quality_ncrs')) {
            return $this->empty('No NCR register exists yet.');
        }
        $base = fn () => DB::table('quality_ncrs')->whereNull('deleted_at');
        $total = (int) $base()->count();
        if ($total === 0) {
            return $this->empty('No NCRs have been recorded yet.');
        }
        $open = (int) $base()->whereNotIn('status', self::NCR_CLOSED)->count();
        $critical = (int) $base()->whereNotIn('status', self::NCR_CLOSED)->where('severity', 'critical')->count();
        $byStatus = $base()->selectRaw('status, COUNT(*) as n')->groupBy('status')->orderByDesc('n')->get();
        $latest = $base()->max('detected_date');

        $list = $base();
        if ($status === 'open') {
            $list->whereNotIn('status', self::NCR_CLOSED);
        } elseif ($status === 'closed') {
            $list->whereIn('status', self::NCR_CLOSED);
        }
        $rows = $list->orderByDesc('detected_date')->orderByDesc('id')->limit(self::LIST_LIMIT)
            ->get(['ncr_number', 'title', 'severity', 'status', 'detected_date']);

        return [
            'text' => "NCR register: {$total} in total, {$open} open ({$critical} critical)".($latest ? ', latest detected '.$this->date($latest) : '').'.',
            'blocks' => [
                ['type' => 'stats', 'items' => [
                    ['k' => 'NCRs recorded', 'v' => (string) $total],
                    ['k' => 'Open', 'v' => (string) $open],
                    ['k' => 'Critical open', 'v' => (string) $critical],
                    ['k' => 'Latest detected', 'v' => $latest ? $this->date($latest) : '—'],
                ]],
                ['type' => 'donut', 'title' => 'NCRs by status', 'items' => $byStatus->map(fn ($r) => ['label' => $this->words($r->status), 'value' => (int) $r->n])->all()],
                ['type' => 'table', 'columns' => ['NCR', 'Title', 'Severity', 'Status', 'Detected'], 'rows' => $rows->map(fn ($r) => [
                    (string) ($r->ncr_number ?: '—'), (string) ($r->title ?: '—'), $this->words($r->severity), $this->words($r->status), $r->detected_date ? $this->date($r->detected_date) : '—',
                ])->all()],
            ],
            'data' => ['total' => $total, 'open' => $open, 'critical_open' => $critical, 'latest_detected' => $latest],
        ];
    }

    private function rfiStatus(): array
    {
        if (! Schema::hasTable('daily_works')) {
            return $this->empty('No RFI register exists yet.');
        }
        $base = fn () => DB::table('daily_works')->whereNull('deleted_at');
        $total = (int) $base()->count();
        if ($total === 0) {
            return $this->empty('No RFIs have been recorded yet.');
        }
        $open = (int) $base()->whereIn('status', self::RFI_OPEN)->count();
        $resubmission = (int) $base()->where('status', DailyWork::STATUS_RESUBMISSION)->count();
        $passed = (int) $base()->where('inspection_result', DailyWork::INSPECTION_PASS)->count();
        $failed = (int) $base()->where('inspection_result', DailyWork::INSPECTION_FAIL)->count();
        $latest = $base()->max('date');
        $byStatus = $base()->selectRaw('status, COUNT(*) as n')->groupBy('status')->orderByDesc('n')->get();

        return [
            'text' => "RFIs: {$total} recorded, {$open} open ({$resubmission} for resubmission); inspections recorded {$passed} pass and {$failed} fail".($latest ? '; latest RFI dated '.$this->date($latest) : '').'.',
            'blocks' => [
                ['type' => 'stats', 'items' => [
                    ['k' => 'RFIs recorded', 'v' => number_format($total)],
                    ['k' => 'Open', 'v' => number_format($open), 'd' => number_format($resubmission).' for resubmission'],
                    ['k' => 'Inspection pass / fail', 'v' => "{$passed} / {$failed}"],
                    ['k' => 'Latest RFI', 'v' => $latest ? $this->date($latest) : '—'],
                ]],
                ['type' => 'bar', 'title' => 'RFIs by status', 'items' => $byStatus->map(fn ($r) => ['label' => $this->words($r->status), 'value' => (int) $r->n])->all()],
            ],
            'data' => ['total' => $total, 'open' => $open, 'resubmission' => $resubmission, 'pass' => $passed, 'fail' => $failed, 'latest' => $latest],
        ];
    }

    private function objections(): array
    {
        if (! Schema::hasTable('rfi_objections')) {
            return $this->empty('No RFI objections register exists yet.');
        }
        $base = fn () => DB::table('rfi_objections')->whereNull('deleted_at');
        $total = (int) $base()->count();
        if ($total === 0) {
            return $this->empty('No RFI objections have been raised yet.');
        }
        $byStatus = $base()->selectRaw('status, COUNT(*) as n')->groupBy('status')->orderByDesc('n')->get();
        $byCategory = $base()->selectRaw('category, COUNT(*) as n')->groupBy('category')->orderByDesc('n')->get();

        return [
            'text' => "{$total} RFI objection".($total === 1 ? '' : 's').' recorded: '.$byStatus->map(fn ($r) => $this->words($r->status).' '.$r->n)->implode(', ').'.',
            'blocks' => [
                ['type' => 'donut', 'title' => 'Objections by status', 'items' => $byStatus->map(fn ($r) => ['label' => $this->words($r->status), 'value' => (int) $r->n])->all()],
                ['type' => 'bar', 'title' => 'Objections by category', 'items' => $byCategory->map(fn ($r) => ['label' => $r->category ? $this->words($r->category) : 'Not categorised', 'value' => (int) $r->n])->all()],
            ],
            'data' => ['total' => $total],
        ];
    }

    private function siteInstructions(string $status): array
    {
        if (! Schema::hasTable('site_instructions')) {
            return $this->empty('No site instruction register exists yet.');
        }
        $list = DB::table('site_instructions');
        if ($status !== 'all') {
            $list->where('status', $status);
        }
        $rows = $list->orderByDesc('issued_date')->orderByDesc('id')->limit(self::LIST_LIMIT)
            ->get(['si_number', 'category', 'location', 'summary', 'description', 'status', 'issued_date']);
        $counts = DB::table('site_instructions')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        if ($rows->isEmpty()) {
            return $this->empty($status === 'all' ? 'No site instructions have been recorded yet.' : "There are no {$status} site instructions.");
        }

        return [
            'text' => 'Site instructions: '.$counts->map(fn ($n, $s) => $this->words((string) $s).' '.$n)->implode(', ').'.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['SI', 'Category', 'Location', 'Summary', 'Status', 'Issued'],
                'rows' => $rows->map(fn ($r) => [
                    (string) ($r->si_number ?: '—'),
                    $this->words($r->category),
                    (string) ($r->location ?: '—'),
                    mb_strimwidth((string) ($r->summary ?: $r->description ?: '—'), 0, 90, '…'),
                    $this->words($r->status),
                    $r->issued_date ? $this->date($r->issued_date) : '—',
                ])->all(),
            ]],
            'data' => ['counts' => $counts->all()],
        ];
    }

    private function words(?string $value): string
    {
        return $value ? ucfirst(str_replace(['_', '-'], ' ', $value)) : '—';
    }

    private function date(string $value): string
    {
        return date('d M Y', strtotime($value));
    }

    /** @return array{text: string, blocks: array<int, mixed>, data: array<string, mixed>} */
    private function empty(string $message): array
    {
        return ['text' => $message, 'blocks' => [], 'data' => ['empty' => true]];
    }
}
