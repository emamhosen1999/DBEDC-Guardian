<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\RequestLog;
use App\Services\Access\AccessAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Permission gating lives in routes/web.php: request_logs.view (read/export), request_logs.delete (single and
 * bulk delete) and request_logs.clear_all (plus the Super Administrator role, enforced here).
 */
class RequestLogController extends Controller
{
    /** Columns shown in the table; the heavy JSON/body columns are only loaded by show(). */
    private const LIST_COLUMNS = ['id', 'ip_address', 'method', 'url', 'user_agent', 'response_status', 'user_id', 'duration_ms', 'created_at'];

    public function index()
    {
        return Inertia::render('Settings/RequestLogs', [
            'title' => 'Request Logs',
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $logs = $this->filtered($request)
            ->with('user:employee_id,name')
            ->select(self::LIST_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) min(max((int) $request->query('per_page', 50), 5), 200));

        return response()->json($logs);
    }

    public function show(int $id): JsonResponse
    {
        $log = RequestLog::with('user:employee_id,name')->find($id);

        if (! $log) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        return response()->json($log);
    }

    public function destroy(int $id): JsonResponse
    {
        $log = RequestLog::find($id);

        if (! $log) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        $log->delete();

        return response()->json(['message' => 'Log deleted successfully']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        RequestLog::whereIn('id', $data['ids'])->delete();

        return response()->json(['message' => 'Logs deleted successfully']);
    }

    public function clearAll(Request $request, AccessAudit $audit): JsonResponse
    {
        abort_unless($request->user()?->hasRole('Super Administrator'), 403, 'Only a Super Administrator may clear all request logs.');

        if ($request->input('confirm') !== 'DELETE_ALL') {
            return response()->json(['message' => 'Confirmation required'], 400);
        }

        $before = RequestLog::count();

        // Audit first: the destructive step must never happen without a record of who did it.
        $audit->record('request_logs.cleared', 'request_logs', null, ['rows' => $before], null);

        RequestLog::truncate();

        return response()->json(['message' => 'All logs cleared successfully']);
    }

    public function export(Request $request)
    {
        $logs = $this->filtered($request)
            ->with('user:employee_id,name')
            ->select(self::LIST_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10000)
            ->get();

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['ID', 'IP Address', 'Method', 'URL', 'User Agent', 'Status', 'Duration (ms)', 'User', 'Created At']);

        foreach ($logs as $log) {
            fputcsv($csv, [
                $log->id,
                $log->ip_address,
                $log->method,
                $log->url,
                $log->user_agent,
                $log->response_status,
                $log->duration_ms,
                $log->user ? $log->user->name : 'Guest',
                $log->created_at,
            ]);
        }

        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        return response($csvContent)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="request_logs_'.date('Y-m-d_H-i-s').'.csv"');
    }

    /**
     * @return Builder<RequestLog>
     */
    private function filtered(Request $request): Builder
    {
        $filters = $request->validate([
            'ip_address' => ['nullable', 'string', 'max:45'],
            'user_id' => ['nullable', 'string', 'max:64'],
            'method' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        return RequestLog::query()
            ->when($filters['ip_address'] ?? null, fn (Builder $q, $v) => $q->where('ip_address', 'like', '%'.$v.'%'))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $v) => $q->where('user_id', $v))
            ->when($filters['method'] ?? null, fn (Builder $q, $v) => $q->where('method', strtoupper($v)))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('response_status', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where('url', 'like', '%'.$v.'%'))
            ->when($filters['start_date'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['end_date'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'));
    }
}
