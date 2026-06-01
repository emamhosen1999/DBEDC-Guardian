<?php

namespace App\Services\Monitoring;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class LogParserService
{
    /**
     * Get error summary from DB logs
     */
    public function getErrorSummary()
    {
        $last24h = Carbon::now()->subDay();

        $errorCounts = DB::table('error_logs')
            ->where('created_at', '>=', $last24h)
            ->selectRaw('
                COUNT(*) as total_errors,
                SUM(CASE WHEN resolved = 0 THEN 1 ELSE 0 END) as unresolved_errors,
                COUNT(DISTINCT user_id) as affected_users
            ')
            ->first();

        $recentErrors = DB::table('error_logs')
            ->where('created_at', '>=', $last24h)
            ->where('resolved', false)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return [
            'total_errors' => $errorCounts->total_errors ?? 0,
            'unresolved_errors' => $errorCounts->unresolved_errors ?? 0,
            'affected_users' => $errorCounts->affected_users ?? 0,
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * Get error metrics for specific period
     */
    public function getErrorMetrics($period)
    {
        $hours = match ($period) {
            '1h' => 1,
            '6h' => 6,
            '24h' => 24,
            '7d' => 168,
            default => 24
        };

        $startTime = Carbon::now()->subHours($hours);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return [
                'error_trends' => DB::table('error_logs')
                    ->where('created_at', '>=', $startTime)
                    ->selectRaw('strftime("%Y-%m-%d %H:00:00", created_at) as hour, COUNT(*) as count')
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get(),
                'error_types' => DB::table('error_logs')
                    ->where('created_at', '>=', $startTime)
                    ->selectRaw('substr(message, 1, 50) as error_type, COUNT(*) as count')
                    ->groupBy('error_type')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get(),
            ];
        }

        return [
            'error_trends' => DB::table('error_logs')
                ->where('created_at', '>=', $startTime)
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour, COUNT(*) as count')
                ->groupBy('hour')
                ->orderBy('hour')
                ->get(),
            'error_types' => DB::table('error_logs')
                ->where('created_at', '>=', $startTime)
                ->selectRaw('LEFT(message, 50) as error_type, COUNT(*) as count')
                ->groupBy('error_type')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * Get list of filesystem log files
     */
    public function getLogFiles()
    {
        $logPath = storage_path('logs');
        $logFiles = [];

        if (File::isDirectory($logPath)) {
            $files = File::files($logPath);
            foreach ($files as $file) {
                if ($file->getExtension() === 'log') {
                    $logFiles[] = [
                        'file' => $file->getFilename(),
                        'size_mb' => round($file->getSize() / 1024 / 1024, 2),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];
                }
            }
        }

        return $logFiles;
    }

    /**
     * Read the end of a log file (last N lines)
     */
    public function readLogFile($filename, $linesCount = 100)
    {
        $filePath = storage_path('logs/'.$filename);

        if (!File::exists($filePath) || !File::isReadable($filePath)) {
            return 'Log file not found or not readable.';
        }

        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $linesCount);
        $file->seek($startLine);

        $lines = [];
        while (!$file->eof()) {
            $lines[] = $file->fgets();
        }

        return implode('', array_filter($lines));
    }

    /**
     * Clear a log file (truncate it)
     */
    public function clearLogFile($filename)
    {
        $filePath = storage_path('logs/'.$filename);

        if (File::exists($filePath)) {
            File::put($filePath, '');
            return true;
        }

        return false;
    }

    /**
     * Perform analysis on filesystem log files
     */
    public function analyzeLogFiles()
    {
        $largeLogs = [];
        $logPath = storage_path('logs');

        if (File::isDirectory($logPath)) {
            $files = glob($logPath.'/*.log');
            foreach ($files as $logFile) {
                $size = filesize($logFile);
                if ($size > 10 * 1024 * 1024) { // Files larger than 10MB
                    $largeLogs[] = [
                        'file' => basename($logFile),
                        'size_mb' => round($size / 1024 / 1024, 2),
                        'modified' => date('Y-m-d H:i:s', filemtime($logFile)),
                    ];
                }
            }
        }

        return $largeLogs;
    }
}
