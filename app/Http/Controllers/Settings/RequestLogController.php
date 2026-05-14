<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\RequestLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RequestLogController extends Controller
{
    public function index()
    {
        return Inertia::render('Settings/RequestLogs', [
            'title' => 'Request Logs',
        ]);
    }

    public function list(Request $request)
    {
        $query = RequestLog::with('user:id,name,employee_id');

        // Filters
        if ($request->has('ip_address') && $request->ip_address) {
            $query->where('ip_address', 'like', '%' . $request->ip_address . '%');
        }

        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('method') && $request->method) {
            $query->where('method', $request->method);
        }

        if ($request->has('status') && $request->status) {
            $query->where('response_status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $query->where('url', 'like', '%' . $request->search . '%');
        }

        if ($request->has('start_date') && $request->start_date) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json($logs);
    }

    public function show($id)
    {
        $log = RequestLog::with('user:id,name,employee_id')->find($id);

        if (! $log) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        return response()->json($log);
    }

    public function destroy($id)
    {
        $log = RequestLog::find($id);

        if (! $log) {
            return response()->json(['message' => 'Log not found'], 404);
        }

        $log->delete();

        return response()->json(['message' => 'Log deleted successfully']);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->get('ids', []);

        if (empty($ids)) {
            return response()->json(['message' => 'No logs selected'], 400);
        }

        RequestLog::whereIn('id', $ids)->delete();

        return response()->json(['message' => 'Logs deleted successfully']);
    }

    public function clearAll(Request $request)
    {
        if (! $request->has('confirm') || $request->confirm !== 'DELETE_ALL') {
            return response()->json(['message' => 'Confirmation required'], 400);
        }

        RequestLog::truncate();

        return response()->json(['message' => 'All logs cleared successfully']);
    }

    public function export(Request $request)
    {
        $query = RequestLog::with('user:id,name,employee_id');

        // Apply same filters as list method
        if ($request->has('ip_address') && $request->ip_address) {
            $query->where('ip_address', 'like', '%' . $request->ip_address . '%');
        }

        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('method') && $request->method) {
            $query->where('method', $request->method);
        }

        if ($request->has('status') && $request->status) {
            $query->where('response_status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $query->where('url', 'like', '%' . $request->search . '%');
        }

        if ($request->has('start_date') && $request->start_date) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        $logs = $query->orderBy('created_at', 'desc')
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
            ->header('Content-Disposition', 'attachment; filename="request_logs_' . date('Y-m-d_H-i-s') . '.csv"');
    }
}
