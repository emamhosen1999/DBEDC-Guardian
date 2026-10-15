<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailyWork\RegisterDailyWorksRequest;
use App\Services\DailyWork\DailyWorkExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exports the register as the viewer sees it: same filters, same scope, gated on daily-works.export. */
class DailyWorkExportController extends Controller
{
    public function __construct(private readonly DailyWorkExportService $export) {}

    /** How many rows the current filters would export (so the dialog can say so before downloading). */
    public function count(RegisterDailyWorksRequest $request): JsonResponse
    {
        $n = $this->export->count($request->user(), $request->filters());

        return response()->json(['rows' => $n, 'max' => DailyWorkExportService::MAX_ROWS, 'columns' => DailyWorkExportService::columns(), 'default' => DailyWorkExportService::DEFAULT]);
    }

    public function download(RegisterDailyWorksRequest $request): StreamedResponse
    {
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['csv', 'xls'])],
            'columns' => ['nullable', 'array', 'max:30'],
            'columns.*' => ['string', Rule::in(array_keys(DailyWorkExportService::columns()))],
        ]);

        return $this->export->stream($request->user(), $request->filters(), $data['columns'] ?? [], $data['format'] ?? 'csv', (string) $request->query('sort', 'date'), (string) $request->query('dir', 'desc'));
    }
}
