<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailyWork\RegisterDailyWorksRequest;
use App\Services\DailyWork\DailyWorkSummaryService;
use Illuminate\Http\JsonResponse;

/** Summary tab data: one request, database aggregates over the viewer's scope. */
class DailyWorkSummaryDataController extends Controller
{
    public function __invoke(RegisterDailyWorksRequest $request, DailyWorkSummaryService $summary): JsonResponse
    {
        return response()->json($summary->summary($request->user(), $request->filters(), (string) $request->query('period', '365d')));
    }
}
