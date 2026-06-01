<?php

namespace App\Services\Monitoring;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemHealthService
{
    /**
     * Get system health check
     */
    public function getSystemHealthCheck()
    {
        $health = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'storage' => $this->checkStorageHealth(),
            'queues' => $this->checkQueueHealth(),
        ];

        $overallStatus = collect($health)->every(fn ($check) => $check['status'] === 'healthy')
            ? 'healthy'
            : (collect($health)->contains(fn ($check) => $check['status'] === 'critical')
                ? 'critical'
                : 'warning');

        return [
            'overall_status' => $overallStatus,
            'checks' => $health,
            'last_check' => now()->toISOString(),
        ];
    }

    /**
     * Check database health
     */
    public function checkDatabaseHealth()
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $responseTime = (microtime(true) - $start) * 1000;

            return [
                'status' => $responseTime < 100 ? 'healthy' : ($responseTime < 500 ? 'warning' : 'critical'),
                'response_time' => round($responseTime, 2),
                'message' => $responseTime < 100 ? 'Database responding normally' : 'Database response time elevated',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'response_time' => null,
                'message' => 'Database connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check cache health
     */
    public function checkCacheHealth()
    {
        try {
            $testKey = 'health_check_'.now()->timestamp;
            Cache::put($testKey, 'test', 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            return [
                'status' => $retrieved === 'test' ? 'healthy' : 'warning',
                'message' => $retrieved === 'test' ? 'Cache working normally' : 'Cache read/write issues',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Cache system failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check storage health
     */
    public function checkStorageHealth()
    {
        try {
            $path = storage_path();
            $freeBytes = disk_free_space($path);
            $totalBytes = disk_total_space($path);
            $usedPercent = (($totalBytes - $freeBytes) / $totalBytes) * 100;

            return [
                'status' => $usedPercent < 80 ? 'healthy' : ($usedPercent < 90 ? 'warning' : 'critical'),
                'used_percent' => round($usedPercent, 1),
                'free_space' => $this->formatBytes($freeBytes),
                'message' => $usedPercent < 80 ? 'Storage space adequate' : 'Storage space running low',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Could not check storage: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Check queue health
     */
    public function checkQueueHealth()
    {
        try {
            $failedJobs = DB::table('failed_jobs')->count();
            $pendingJobs = DB::table('jobs')->count();

            return [
                'status' => $failedJobs < 10 ? 'healthy' : ($failedJobs < 50 ? 'warning' : 'critical'),
                'failed_jobs' => $failedJobs,
                'pending_jobs' => $pendingJobs,
                'message' => $failedJobs < 10 ? 'Queue processing normally' : 'High number of failed jobs',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'warning',
                'message' => 'Could not check queues: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Format bytes to human readable format
     */
    public function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }

    /**
     * Get system resources monitoring - CPU, Memory, Disk
     */
    public function getSystemResources()
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'network' => $this->getNetworkStats(),
            'processes' => $this->getProcessStats(),
        ];
    }

    public function getCpuUsage()
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                $load = sys_getloadavg();

                return [
                    'load_1min' => $load[0] ?? 0,
                    'load_5min' => $load[1] ?? 0,
                    'load_15min' => $load[2] ?? 0,
                    'cores' => $this->getCpuCores(),
                ];
            }

            return ['error' => 'CPU stats not available on this platform'];
        } catch (\Exception $e) {
            return ['error' => 'CPU stats unavailable'];
        }
    }

    public function getCpuCores()
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return (int) shell_exec('nproc') ?: 1;
        }

        return 1;
    }

    public function getDiskUsage()
    {
        try {
            $path = storage_path();

            return [
                'total_space' => disk_total_space($path),
                'free_space' => disk_free_space($path),
                'used_space' => disk_total_space($path) - disk_free_space($path),
                'usage_percent' => round(((disk_total_space($path) - disk_free_space($path)) / disk_total_space($path)) * 100, 2),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Disk usage unavailable'];
        }
    }

    public function getNetworkStats()
    {
        try {
            return [
                'active_sessions' => DB::table('sessions')->count(),
                'requests_per_minute' => $this->getRequestsPerMinute(),
                'bandwidth_usage' => 'monitoring_required',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Network stats unavailable'];
        }
    }

    public function getRequestsPerMinute()
    {
        try {
            return DB::table('performance_metrics')
                ->where('created_at', '>=', now()->subMinute())
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getProcessStats()
    {
        try {
            return [
                'php_processes' => $this->getPhpProcessCount(),
                'memory_per_process' => memory_get_usage(true),
                'max_execution_time' => ini_get('max_execution_time'),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Process stats unavailable'];
        }
    }

    public function getPhpProcessCount()
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return (int) shell_exec("ps aux | grep -c '[p]hp'") ?: 1;
        }

        return 1;
    }

    public function getServerLoad()
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();

            return [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2],
            ];
        }

        return null;
    }

    public function getMemoryUsage()
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit'),
        ];
    }

    public function getSystemUptime()
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime = file_get_contents('/proc/uptime');

            return floatval(explode(' ', $uptime)[0]);
        }

        return null;
    }

    public function getUptimeMetrics()
    {
        try {
            $uptime = $this->getSystemUptime();

            return [
                'system_uptime_hours' => $uptime ? round($uptime / 3600, 2) : null,
                'application_start_time' => Cache::get('app_start_time', now()->toISOString()),
                'last_restart' => $this->getLastRestartTime(),
                'availability_percentage' => $this->calculateAvailabilityPercentage(),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Uptime metrics unavailable'];
        }
    }

    public function getLastRestartTime()
    {
        return Cache::get('last_restart_time', 'Unknown');
    }

    public function calculateAvailabilityPercentage()
    {
        try {
            $totalRequests = DB::table('performance_metrics')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $errorRequests = DB::table('error_logs')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($totalRequests > 0) {
                return round((($totalRequests - $errorRequests) / $totalRequests) * 100, 3);
            }

            return 99.9;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getServiceHealthChecks()
    {
        return [
            'database_connectivity' => $this->checkDatabaseHealth(),
            'file_system_access' => $this->checkFileSystemHealth(),
            'external_services' => $this->checkExternalServices(),
            'cache_system' => $this->checkCacheHealth(),
        ];
    }

    public function checkFileSystemHealth()
    {
        try {
            $testFile = storage_path('logs/health_check.tmp');
            file_put_contents($testFile, 'health_check_'.time());
            $content = file_get_contents($testFile);
            unlink($testFile);

            return ['status' => 'healthy', 'writable' => true, 'readable' => true];
        } catch (\Exception $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    public function checkExternalServices()
    {
        return [
            'mail_service' => ['status' => 'not_tested'],
            'notification_service' => ['status' => 'not_tested'],
            'backup_service' => ['status' => 'not_tested'],
        ];
    }

    public function getServiceAvailability()
    {
        return [
            'uptime' => $this->getUptimeMetrics(),
            'service_health' => $this->getServiceHealthChecks(),
            'incident_history' => $this->getIncidentHistory(),
            'sla_compliance' => $this->getSLACompliance(),
        ];
    }

    public function getIncidentHistory()
    {
        try {
            return DB::table('error_logs')
                ->select('error_type', 'message', 'created_at')
                ->where('severity', 'critical')
                ->where('created_at', '>=', now()->subWeek())
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getSLACompliance()
    {
        try {
            $availabilityTarget = 99.9;
            $responseTimeTarget = 500;

            $actualAvailability = $this->calculateAvailabilityPercentage();
            $actualResponseTime = DB::table('performance_metrics')
                ->where('created_at', '>=', now()->subDay())
                ->avg('execution_time_ms');

            return [
                'availability' => [
                    'target' => $availabilityTarget,
                    'actual' => $actualAvailability,
                    'compliant' => $actualAvailability >= $availabilityTarget,
                ],
                'response_time' => [
                    'target' => $responseTimeTarget,
                    'actual' => round($actualResponseTime, 2),
                    'compliant' => $actualResponseTime <= $responseTimeTarget,
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'SLA compliance calculation unavailable'];
        }
    }

    public function getCapacityPlanningData()
    {
        return [
            'growth_trends' => $this->getGrowthTrends(),
            'resource_forecasting' => $this->getResourceForecasting(),
            'capacity_alerts' => $this->getCapacityAlerts(),
            'scaling_recommendations' => $this->getScalingRecommendations(),
        ];
    }

    public function getGrowthTrends()
    {
        try {
            $userGrowth = DB::table('users')
                ->selectRaw('DATE(created_at) as date, COUNT(*) as new_users')
                ->where('created_at', '>=', now()->subMonth())
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $dataGrowth = $this->getDataGrowthTrends();

            return [
                'user_growth' => $userGrowth,
                'data_growth' => $dataGrowth,
                'traffic_growth' => $this->getTrafficGrowthTrends(),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Growth trend analysis unavailable'];
        }
    }

    public function getDataGrowthTrends()
    {
        try {
            $tables = ['users', 'daily_works', 'attendances', 'letters'];
            $growth = [];

            foreach ($tables as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'created_at')) {
                    $growth[$table] = DB::table($table)
                        ->selectRaw('DATE(created_at) as date, COUNT(*) as records')
                        ->where('created_at', '>=', now()->subMonth())
                        ->groupBy('date')
                        ->orderBy('date')
                        ->get();
                }
            }

            return $growth;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getTrafficGrowthTrends()
    {
        try {
            return DB::table('performance_metrics')
                ->selectRaw('DATE(created_at) as date, COUNT(*) as requests, AVG(execution_time_ms) as avg_response')
                ->where('created_at', '>=', now()->subMonth())
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getResourceForecasting()
    {
        return [
            'database_size_projection' => $this->projectDatabaseSize(),
            'user_capacity_projection' => $this->projectUserCapacity(),
            'storage_requirements' => $this->projectStorageRequirements(),
            'performance_projection' => $this->projectPerformanceRequirements(),
        ];
    }

    public function projectDatabaseSize()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $size = 0.1;
            } else {
                $currentSize = DB::selectOne('
                    SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE()
                ');
                $size = $currentSize->size_mb ?? 0;
            }

            $monthlyGrowth = 0.1;

            return [
                'current_size_mb' => $size,
                'projected_3_months' => round($size * (1 + $monthlyGrowth) ** 3, 2),
                'projected_6_months' => round($size * (1 + $monthlyGrowth) ** 6, 2),
                'projected_12_months' => round($size * (1 + $monthlyGrowth) ** 12, 2),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Database size projection unavailable'];
        }
    }

    public function projectUserCapacity()
    {
        try {
            $currentUsers = DB::table('users')->count();
            $monthlyGrowthRate = 0.05;

            return [
                'current_users' => $currentUsers,
                'projected_3_months' => round($currentUsers * (1 + $monthlyGrowthRate) ** 3),
                'projected_6_months' => round($currentUsers * (1 + $monthlyGrowthRate) ** 6),
                'projected_12_months' => round($currentUsers * (1 + $monthlyGrowthRate) ** 12),
            ];
        } catch (\Exception $e) {
            return ['error' => 'User capacity projection unavailable'];
        }
    }

    public function projectStorageRequirements()
    {
        $diskUsage = $this->getDiskUsage();
        $monthlyGrowthRate = 0.15;

        if (isset($diskUsage['used_space'])) {
            $currentUsedGB = $diskUsage['used_space'] / (1024 ** 3);

            return [
                'current_used_gb' => round($currentUsedGB, 2),
                'projected_3_months_gb' => round($currentUsedGB * (1 + $monthlyGrowthRate) ** 3, 2),
                'projected_6_months_gb' => round($currentUsedGB * (1 + $monthlyGrowthRate) ** 6, 2),
                'projected_12_months_gb' => round($currentUsedGB * (1 + $monthlyGrowthRate) ** 12, 2),
            ];
        }

        return ['error' => 'Storage projection unavailable'];
    }

    public function projectPerformanceRequirements()
    {
        try {
            $avgResponseTime = DB::table('performance_metrics')
                ->where('created_at', '>=', now()->subWeek())
                ->avg('execution_time_ms');

            return [
                'current_avg_response_ms' => round($avgResponseTime, 2),
                'projected_load_increase' => '20% quarterly',
                'recommended_optimizations' => [
                    'database_indexing',
                    'caching_strategy',
                    'query_optimization',
                    'server_scaling',
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'Performance projection unavailable'];
        }
    }

    public function getCapacityAlerts()
    {
        $alerts = [];

        $diskUsage = $this->getDiskUsage();
        if (isset($diskUsage['usage_percent']) && $diskUsage['usage_percent'] > 80) {
            $alerts[] = [
                'type' => 'storage',
                'severity' => $diskUsage['usage_percent'] > 90 ? 'critical' : 'warning',
                'message' => "Disk usage at {$diskUsage['usage_percent']}%",
                'recommendation' => 'Consider storage cleanup or expansion',
            ];
        }

        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $dbSize = (object)['size_mb' => 0.1];
            } else {
                $dbSize = DB::selectOne('
                    SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE()
                ');
            }

            if ($dbSize && $dbSize->size_mb > 1000) {
                $alerts[] = [
                    'type' => 'database',
                    'severity' => 'info',
                    'message' => "Database size: {$dbSize->size_mb} MB",
                    'recommendation' => 'Monitor growth and consider archiving old data',
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $alerts;
    }

    public function getScalingRecommendations()
    {
        $recommendations = [];

        try {
            $avgResponseTime = DB::table('performance_metrics')
                ->where('created_at', '>=', now()->subDay())
                ->avg('execution_time_ms');

            if ($avgResponseTime > 500) {
                $recommendations[] = [
                    'category' => 'performance',
                    'priority' => 'high',
                    'recommendation' => 'Optimize slow queries and consider database scaling',
                    'impact' => 'Improve user experience and system responsiveness',
                ];
            }

            // Connection utilization
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $activeCount = 1;
                $maxCount = 1;
            } else {
                $connections = DB::select("SHOW STATUS LIKE 'Threads_%'");
                $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");
                $activeCount = collect($connections)->firstWhere('Variable_name', 'Threads_connected')->Value ?? 0;
                $maxCount = collect($maxConnections)->first()->Value ?? 1;
            }
            $connectionUtilization = round(($activeCount / $maxCount) * 100, 2);

            if ($connectionUtilization > 70) {
                $recommendations[] = [
                    'category' => 'database',
                    'priority' => 'medium',
                    'recommendation' => 'Increase database connection pool size',
                    'impact' => 'Prevent connection bottlenecks',
                ];
            }

            $memoryUsage = $this->getMemoryUsage();
            // memory limit in bytes conversion
            $limitStr = $memoryUsage['limit'];
            $limitBytes = $this->parseIniMemoryLimit($limitStr);
            if ($limitBytes > 0 && $memoryUsage['current'] > ($limitBytes * 0.8)) {
                $recommendations[] = [
                    'category' => 'infrastructure',
                    'priority' => 'high',
                    'recommendation' => 'Increase server memory allocation',
                    'impact' => 'Prevent out-of-memory errors',
                ];
            }

        } catch (\Exception $e) {
            $recommendations[] = [
                'category' => 'monitoring',
                'priority' => 'medium',
                'recommendation' => 'Implement comprehensive performance monitoring',
                'impact' => 'Better visibility into system performance',
            ];
        }

        return $recommendations;
    }

    private function parseIniMemoryLimit($limit)
    {
        $value = trim($limit);
        if ($value === '-1') {
            return PHP_INT_MAX;
        }
        $last = strtolower($value[strlen($value) - 1]);
        $val = (int)$value;
        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }

    public function analyzeDependencies()
    {
        $packageJson = file_exists(base_path('package.json')) ?
            json_decode(file_get_contents(base_path('package.json')), true) : null;

        $composerJson = file_exists(base_path('composer.json')) ?
            json_decode(file_get_contents(base_path('composer.json')), true) : null;

        return [
            'npm_dependencies' => $packageJson ? count($packageJson['dependencies'] ?? []) : 0,
            'npm_dev_dependencies' => $packageJson ? count($packageJson['devDependencies'] ?? []) : 0,
            'composer_dependencies' => $composerJson ? count($composerJson['require'] ?? []) : 0,
            'composer_dev_dependencies' => $composerJson ? count($composerJson['require-dev'] ?? []) : 0,
            'node_modules_size' => $this->getDirectorySize(base_path('node_modules')),
            'vendor_size' => $this->getDirectorySize(base_path('vendor')),
        ];
    }

    public function analyzeCacheUsage()
    {
        $cacheStats = [
            'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
            'routes_cached' => file_exists(base_path('bootstrap/cache/routes-v7.php')),
            'views_cached' => file_exists(storage_path('framework/views')),
            'cache_size' => 0,
        ];

        $cachePath = storage_path('framework/cache');
        if (is_dir($cachePath)) {
            $cacheStats['cache_size'] = $this->getDirectorySize($cachePath);
        }

        return $cacheStats;
    }

    public function generateOptimizationRecommendations()
    {
        return [
            'immediate_actions' => [
                'Enable OPcache for PHP if not already enabled',
                'Configure database query cache',
                'Implement Redis for session and cache storage',
                'Enable Gzip compression on web server',
                'Optimize images and static assets',
            ],
            'performance_improvements' => [
                'Implement lazy loading for large datasets',
                'Add database indexes for frequently queried columns',
                'Use queue workers for time-consuming tasks',
                'Implement CDN for static assets',
                'Consider database connection pooling',
            ],
            'security_enhancements' => [
                'Enable CSRF protection on all forms',
                'Implement rate limiting on API endpoints',
                'Use HTTPS for all communications',
                'Regular security updates for dependencies',
                'Implement proper input validation and sanitization',
            ],
            'maintenance_tasks' => [
                'Set up automated backups',
                'Implement log rotation',
                'Monitor disk space usage',
                'Regular dependency updates',
                'Database maintenance and optimization',
            ],
        ];
    }

    public function getDirectorySize($dir)
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    public function getPerformanceSummary()
    {
        $last24h = Carbon::now()->subDay();

        $metrics = DB::table('performance_metrics')
            ->where('created_at', '>=', $last24h)
            ->selectRaw('
                metric_type,
                COUNT(*) as total_requests,
                AVG(execution_time_ms) as avg_time,
                MAX(execution_time_ms) as max_time,
                MIN(execution_time_ms) as min_time
            ')
            ->groupBy('metric_type')
            ->get();

        $slowQueries = DB::table('performance_metrics')
            ->where('created_at', '>=', $last24h)
            ->where('execution_time_ms', '>', 1000)
            ->count();

        return [
            'metrics' => $metrics,
            'slow_queries_count' => $slowQueries,
            'avg_response_time' => $metrics->avg('avg_time') ?? 0,
            'total_requests' => $metrics->sum('total_requests'),
        ];
    }

    public function getPerformanceMetrics($period)
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
            return DB::table('performance_metrics')
                ->where('created_at', '>=', $startTime)
                ->selectRaw('
                    strftime("%Y-%m-%d %H:00:00", created_at) as hour,
                    metric_type,
                    AVG(execution_time_ms) as avg_time,
                    COUNT(*) as request_count
                ')
                ->groupBy('hour', 'metric_type')
                ->orderBy('hour')
                ->get()
                ->groupBy('metric_type');
        }

        return DB::table('performance_metrics')
            ->where('created_at', '>=', $startTime)
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour,
                metric_type,
                AVG(execution_time_ms) as avg_time,
                COUNT(*) as request_count
            ')
            ->groupBy('hour', 'metric_type')
            ->orderBy('hour')
            ->get()
            ->groupBy('metric_type');
    }

    public function getSystemMetrics()
    {
        return [
            'server_load' => $this->getServerLoad(),
            'memory_usage' => $this->getMemoryUsage(),
            'uptime' => $this->getSystemUptime(),
        ];
    }

    public function analyzeFileSystem(LogParserService $logParserService)
    {
        $analysis = [
            'storage_usage' => [],
            'log_files' => [],
            'temporary_files' => [],
            'recommendations' => [],
        ];

        $storageDirs = ['app', 'framework', 'logs'];
        foreach ($storageDirs as $dir) {
            $path = storage_path($dir);
            if (is_dir($path)) {
                $analysis['storage_usage'][$dir] = $this->getDirectorySize($path);
            }
        }

        $analysis['log_files'] = $logParserService->analyzeLogFiles();

        if (! empty($analysis['log_files'])) {
            $analysis['recommendations'][] = [
                'type' => 'cleanup',
                'priority' => 'medium',
                'message' => 'Large log files detected. Consider log rotation.',
            ];
        }

        return $analysis;
    }
}

