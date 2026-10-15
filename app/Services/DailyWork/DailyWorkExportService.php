<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\Access\AccessAudit;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports exactly the register's filtered, scoped result set (never more than the viewer may see), streamed in chunks so
 * memory stays flat. Excel opens the UTF-8 BOM CSV directly; the .xls flavour is an HTML table Excel also opens, which
 * keeps this dependency-free. Every export is written to the audit trail with its filters and row count.
 */
class DailyWorkExportService
{
    /** key => [heading, value callback]. The default column set is the register's. */
    private const COLUMNS = [
        'number' => 'RFI number', 'date' => 'Date', 'status' => 'Status', 'inspection_result' => 'Inspection result', 'type' => 'Type',
        'description' => 'Description', 'location' => 'Location', 'side' => 'Side', 'jurisdiction' => 'Jurisdiction', 'incharge' => 'In-charge',
        'assigned' => 'Assigned to', 'planned_time' => 'Planned time', 'completion_time' => 'Completed at', 'rfi_submission_date' => 'RFI submitted',
        'rfi_response_status' => 'RFI response', 'rfi_response_date' => 'RFI response date', 'resubmission_count' => 'Resubmissions', 'objections' => 'Active objections',
    ];

    public const DEFAULT = ['number', 'date', 'status', 'type', 'description', 'location', 'side', 'jurisdiction', 'incharge', 'assigned', 'completion_time', 'resubmission_count', 'objections'];

    public const MAX_ROWS = 100000;

    public function __construct(private readonly DailyWorkRegisterService $register) {}

    public static function columns(): array
    {
        return self::COLUMNS;
    }

    public function count(User $viewer, DailyWorkFilters $filters): int
    {
        return (int) $filters->apply($this->register->scopedQuery($viewer))->count();
    }

    /** @param  array<int, string>  $columns */
    public function stream(User $viewer, DailyWorkFilters $filters, array $columns, string $format, string $sort, string $dir): StreamedResponse
    {
        $columns = array_values(array_intersect($columns ?: self::DEFAULT, array_keys(self::COLUMNS)));
        $columns = $columns ?: self::DEFAULT;
        $query = $this->query($viewer, $filters, $sort, $dir);
        $total = min(self::MAX_ROWS, (clone $query)->toBase()->getCountForPagination());
        app(AccessAudit::class)->record('daily-works.exported', DailyWork::class, 'register', null, ['format' => $format, 'rows' => $total, 'filters' => $filters->toArray(), 'columns' => $columns]);

        $name = 'daily-works-'.now()->format('Ymd-His').($format === 'xls' ? '.xls' : '.csv');
        $headings = array_map(fn (string $c): string => self::COLUMNS[$c], $columns);

        return response()->streamDownload(function () use ($query, $columns, $headings, $format): void {
            $out = fopen('php://output', 'w');
            $written = 0;
            if ($format === 'xls') {
                fwrite($out, '<html><head><meta charset="utf-8"></head><body><table border="1"><tr>'.implode('', array_map(fn ($h) => '<th>'.e($h).'</th>', $headings)).'</tr>');
            } else {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headings);
            }
            $query->chunk(1000, function ($works) use ($out, $columns, $format, &$written): bool {
                foreach ($works as $w) {
                    $cells = array_map(fn (string $c) => $this->cell($w, $c, $format), $columns);
                    if ($format === 'xls') {
                        fwrite($out, '<tr>'.implode('', array_map(fn ($v) => '<td style="mso-number-format:\'@\'">'.e((string) $v).'</td>', $cells)).'</tr>');
                    } else {
                        fputcsv($out, $cells);
                    }
                    if (++$written >= self::MAX_ROWS) {
                        return false;
                    }
                }

                return true;
            });
            if ($format === 'xls') {
                fwrite($out, '</table></body></html>');
            }
            fclose($out);
        }, $name, ['Content-Type' => $format === 'xls' ? 'application/vnd.ms-excel; charset=UTF-8' : 'text/csv; charset=UTF-8', 'X-Export-Rows' => (string) $total]);
    }

    private function query(User $viewer, DailyWorkFilters $filters, string $sort, string $dir): Builder
    {
        $column = DailyWorkRegisterService::SORTS[$sort] ?? 'date';

        return $filters->apply($this->register->scopedQuery($viewer))
            ->with(['inchargeUser:employee_id,name', 'assignedUser:employee_id,name'])
            ->withCount('activeObjections as active_objections_count')
            ->orderBy($column, strtolower($dir) === 'asc' ? 'asc' : 'desc')->orderBy('id');
    }

    /** @var array<int, string>|null */
    private ?array $codes = null;

    private function codes(): array
    {
        return $this->codes ??= Jurisdiction::withTrashed()->pluck('code', 'id')->all();
    }

    private function cell(DailyWork $w, string $c, string $format): string
    {
        $v = match ($c) {
            'status' => DailyWorkWorkflow::normalise($w->status),
            'date', 'rfi_submission_date', 'rfi_response_date' => $w->{$c}?->toDateString(),
            'completion_time' => $w->completion_time?->format('Y-m-d H:i'),
            'incharge' => $w->inchargeUser?->name ?? $w->incharge,
            'assigned' => $w->assignedUser?->name ?? $w->assigned,
            'jurisdiction' => $w->jurisdiction_id ? (string) ($this->codes()[$w->jurisdiction_id] ?? '') : null,
            'objections' => (int) ($w->getAttributes()['active_objections_count'] ?? 0),
            default => $w->{$c},
        };
        $v = (string) ($v ?? '');

        // Spreadsheet formula injection: a cell that starts like a formula is stored as text.
        return $format === 'csv' && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v) ? "'".$v : $v;
    }
}
