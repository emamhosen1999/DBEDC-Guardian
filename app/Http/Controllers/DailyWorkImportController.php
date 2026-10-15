<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\DailyWorkImportBatch;
use App\Services\DailyWork\DailyWorkImportReversal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import batches: review what an import did, download the report of rejected rows, and reverse a batch (its own permission,
 * audited, soft). Listing and reviewing need daily-works.import; reversing needs daily-works.import.reverse.
 */
class DailyWorkImportController extends Controller
{
    public function __construct(private readonly DailyWorkImportReversal $reversal) {}

    public function index(Request $request): JsonResponse
    {
        $page = DailyWorkImportBatch::query()->where('status', '!=', DailyWorkImportBatch::PREVIEWED)->with('creator:employee_id,name')->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $page->getCollection()->map(fn (DailyWorkImportBatch $b): array => $this->row($b))->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'can_reverse' => $request->user()->can('reverseImport', DailyWork::class),
        ]);
    }

    public function show(Request $request, DailyWorkImportBatch $batch): JsonResponse
    {
        $works = DailyWork::withTrashed()->where('import_batch_id', $batch->id)->orderBy('date')->orderBy('id')->limit(300)->get(['id', 'number', 'date', 'status', 'location', 'deleted_at']);

        return response()->json([
            'batch' => $this->row($batch) + ['rejected' => $batch->summary['rejected'] ?? []],
            'works' => $works->map(fn (DailyWork $w): array => ['id' => $w->id, 'number' => $w->number, 'date' => $w->date?->toDateString(), 'status' => $w->status, 'location' => $w->location, 'deleted' => $w->trashed()])->all(),
            'plan' => $batch->status === DailyWorkImportBatch::COMMITTED ? $this->reversal->plan($batch) : null,
            'can_reverse' => $request->user()->can('reverseImport', DailyWork::class),
        ]);
    }

    /** The rejected rows with their reasons, as a CSV the person can fix and import again. */
    public function errors(DailyWorkImportBatch $batch): StreamedResponse
    {
        $rows = $batch->summary['rejected'] ?? [];

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
            fputcsv($out, ['Sheet', 'Row', 'Date', 'RFI number', 'Type', 'Location', 'Description', 'Why it was rejected']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['sheet'], $r['row'], $r['date'], $r['rfi_number'], $r['type'], $r['location'], $r['description'], implode(' | ', $r['reasons'])]);
            }
            fclose($out);
        }, 'import-'.$batch->id.'-rejected-rows.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function reverse(Request $request, DailyWorkImportBatch $batch): JsonResponse
    {
        $this->authorize('reverseImport', DailyWork::class);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500'], 'skip_conflicts' => ['nullable', 'boolean']]);
        $result = $this->reversal->reverse($batch, $data['reason'], $request->user(), (bool) ($data['skip_conflicts'] ?? false));

        return response()->json(['message' => "Import reversed: {$result['deleted']} works removed (they can be restored), {$result['reverted']} resubmissions taken back.", 'result' => $result]);
    }

    /** @return array<string, mixed> */
    private function row(DailyWorkImportBatch $b): array
    {
        return [
            'id' => $b->id, 'filename' => $b->filename, 'status' => $b->status, 'sheets' => $b->sheets, 'rows_total' => $b->rows_total, 'rows_error' => $b->rows_error, 'rows_warning' => $b->rows_warning,
            'rows_created' => $b->rows_created, 'rows_resubmitted' => $b->rows_resubmitted, 'rows_skipped' => $b->rows_skipped, 'dates' => $b->summary['dates'] ?? [],
            'by' => $b->creator?->name, 'committed_at' => $b->committed_at?->toIso8601String(), 'reversed_at' => $b->reversed_at?->toIso8601String(), 'reverse_reason' => $b->reverse_reason,
        ];
    }
}
