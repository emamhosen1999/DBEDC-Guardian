<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseAnalyticsService
{
    /**
     * Get database statistics
     */
    public function getDatabaseStats()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $tables = DB::select("SELECT name as table_name, 0 as table_rows, 0.0 as size_mb FROM sqlite_master WHERE type='table'");

                return [
                    'largest_tables' => $tables,
                    'total_tables' => count($tables),
                ];
            }
            $tables = DB::select('
                SELECT 
                    table_name,
                    table_rows,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                ORDER BY (data_length + index_length) DESC
                LIMIT 10
            ');

            return [
                'largest_tables' => $tables,
                'total_tables' => count(DB::select('SHOW TABLES')),
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Could not retrieve database stats: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get comprehensive database statistics - ISO standard compliant
     */
    public function getComprehensiveDatabaseStats()
    {
        try {
            $stats = [
                'table_analysis' => $this->getAllTablesAnalysis(),
                'index_analysis' => $this->getIndexAnalysis(),
                'storage_analysis' => $this->getStorageAnalysis(),
                'query_performance' => $this->getQueryPerformanceStats(),
                'connection_pool' => $this->getConnectionPoolStats(),
                'replication_status' => $this->getReplicationStatus(),
                'backup_status' => $this->getBackupStatus(),
            ];

            return $stats;
        } catch (\Exception $e) {
            Log::error('Database stats error: '.$e->getMessage());

            return ['error' => 'Unable to retrieve database statistics'];
        }
    }

    public function getAllTablesAnalysis()
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $tables = DB::select("SELECT name as TABLE_NAME, 0 as TABLE_ROWS, 0.0 as size_mb, 0.0 as data_mb, 0.0 as index_mb, 'utf8mb4' as TABLE_COLLATION, 'sqlite' as ENGINE, '' as CREATE_TIME, '' as UPDATE_TIME, '' as CHECK_TIME, 0 as AUTO_INCREMENT FROM sqlite_master WHERE type='table'");
        } else {
            $tables = DB::select('
                SELECT 
                    TABLE_NAME,
                    TABLE_ROWS,
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb,
                    ROUND(DATA_LENGTH / 1024 / 1024, 2) as data_mb,
                    ROUND(INDEX_LENGTH / 1024 / 1024, 2) as index_mb,
                    TABLE_COLLATION,
                    ENGINE,
                    CREATE_TIME,
                    UPDATE_TIME,
                    CHECK_TIME,
                    AUTO_INCREMENT
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE()
                ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
            ');
        }

        return [
            'tables' => $tables,
            'summary' => [
                'total_tables' => count($tables),
                'total_size_mb' => array_sum(array_column($tables, 'size_mb')),
                'total_rows' => array_sum(array_column($tables, 'TABLE_ROWS')),
                'largest_table' => $tables[0] ?? null,
                'engines_used' => array_unique(array_column($tables, 'ENGINE')),
            ],
        ];
    }

    public function getIndexAnalysis()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $indexes = [];
                $duplicateIndexes = [];
            } else {
                $indexes = DB::select('
                    SELECT 
                        TABLE_NAME,
                        INDEX_NAME,
                        COLUMN_NAME,
                        CARDINALITY,
                        SUB_PART,
                        INDEX_TYPE,
                        NON_UNIQUE
                    FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE()
                    ORDER BY TABLE_NAME, INDEX_NAME
                ');

                $duplicateIndexes = DB::select('
                    SELECT 
                        TABLE_NAME,
                        COUNT(*) as duplicate_count,
                        GROUP_CONCAT(INDEX_NAME) as index_names
                    FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE()
                    GROUP BY TABLE_NAME, COLUMN_NAME
                    HAVING COUNT(*) > 1
                ');
            }

            return [
                'indexes' => $indexes,
                'duplicate_indexes' => $duplicateIndexes,
                'summary' => [
                    'total_indexes' => count($indexes),
                    'duplicate_count' => count($duplicateIndexes),
                    'unique_indexes' => count(array_filter($indexes, fn ($i) => isset($i->NON_UNIQUE) && $i->NON_UNIQUE == 0)),
                    'fulltext_indexes' => count(array_filter($indexes, fn ($i) => isset($i->INDEX_TYPE) && $i->INDEX_TYPE == 'FULLTEXT')),
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'Index analysis unavailable'];
        }
    }

    public function getStorageAnalysis()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $storage = [
                    (object) [
                        'ENGINE' => 'sqlite',
                        'table_count' => count(DB::select("SELECT name FROM sqlite_master WHERE type='table'")),
                        'total_size_mb' => 0.0,
                        'avg_size_mb' => 0.0,
                    ],
                ];
                $fragmentation = [];
            } else {
                $storage = DB::select('
                    SELECT 
                        ENGINE,
                        COUNT(*) as table_count,
                        ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as total_size_mb,
                        ROUND(AVG(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as avg_size_mb
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE()
                    GROUP BY ENGINE
                ');

                $fragmentation = DB::select('
                    SELECT 
                        TABLE_NAME,
                        ROUND(DATA_FREE / 1024 / 1024, 2) as fragmentation_mb,
                        ROUND((DATA_FREE / (DATA_LENGTH + INDEX_LENGTH + DATA_FREE)) * 100, 2) as fragmentation_percent
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND DATA_FREE > 0
                    ORDER BY DATA_FREE DESC
                    LIMIT 10
                ');
            }

            return [
                'by_engine' => $storage,
                'fragmentation' => $fragmentation,
                'recommendations' => $this->getStorageRecommendations($fragmentation),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Storage analysis unavailable'];
        }
    }

    public function getStorageRecommendations($fragmentation)
    {
        $recommendations = [];
        foreach ($fragmentation as $table) {
            if ($table->fragmentation_percent > 20) {
                $recommendations[] = [
                    'type' => 'optimize',
                    'table' => $table->TABLE_NAME,
                    'message' => "Table {$table->TABLE_NAME} has {$table->fragmentation_percent}% fragmentation. Consider running OPTIMIZE TABLE.",
                    'priority' => $table->fragmentation_percent > 50 ? 'high' : 'medium',
                ];
            }
        }

        return $recommendations;
    }

    public function getQueryPerformanceStats()
    {
        try {
            $slowQueries = DB::table('performance_metrics')
                ->where('created_at', '>=', now()->subDay())
                ->where('execution_time_ms', '>', 1000)
                ->orderBy('execution_time_ms', 'desc')
                ->limit(10)
                ->get();

            $queryTypes = DB::table('performance_metrics')
                ->select('metric_type', DB::raw('COUNT(*) as count'), DB::raw('AVG(execution_time_ms) as avg_time'))
                ->where('created_at', '>=', now()->subDay())
                ->groupBy('metric_type')
                ->orderBy('avg_time', 'desc')
                ->get();

            return [
                'slow_queries' => $slowQueries,
                'query_types' => $queryTypes,
                'performance_summary' => [
                    'total_queries_24h' => DB::table('performance_metrics')->where('created_at', '>=', now()->subDay())->count(),
                    'avg_execution_time' => DB::table('performance_metrics')->where('created_at', '>=', now()->subDay())->avg('execution_time_ms'),
                    'slowest_query_time' => DB::table('performance_metrics')->where('created_at', '>=', now()->subDay())->max('execution_time_ms'),
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'Query performance data unavailable'];
        }
    }

    public function getConnectionPoolStats()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                return [
                    'active_connections' => 1,
                    'max_connections' => 1,
                    'connection_utilization' => 100,
                ];
            }
            $connections = DB::select("SHOW STATUS LIKE 'Threads_%'");
            $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");

            return [
                'active_connections' => collect($connections)->firstWhere('Variable_name', 'Threads_connected')->Value ?? 0,
                'max_connections' => collect($maxConnections)->first()->Value ?? 0,
                'connection_utilization' => round((collect($connections)->firstWhere('Variable_name', 'Threads_connected')->Value ?? 0) / (collect($maxConnections)->first()->Value ?? 1) * 100, 2),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Connection pool data unavailable'];
        }
    }

    public function getReplicationStatus()
    {
        try {
            return [
                'status' => 'not_configured',
                'lag' => null,
                'last_check' => now(),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Replication status unavailable'];
        }
    }

    public function getBackupStatus()
    {
        try {
            $backupPath = storage_path('backups');
            $backups = [];

            if (is_dir($backupPath)) {
                $files = glob($backupPath.'/*.sql');
                foreach ($files as $file) {
                    $backups[] = [
                        'filename' => basename($file),
                        'size_mb' => round(filesize($file) / 1024 / 1024, 2),
                        'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                    ];
                }
            }

            return [
                'recent_backups' => array_slice($backups, -5),
                'last_backup' => $backups ? max(array_column($backups, 'created_at')) : null,
                'backup_status' => $backups ? 'available' : 'none',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Backup status unavailable'];
        }
    }

    public function getDatabaseOptimizationSuggestions()
    {
        $suggestions = [];

        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $unindexedTables = [];
                $largeTables = [];
            } else {
                // Check for tables without indexes
                $unindexedTables = DB::select('
                    SELECT TABLE_NAME, TABLE_ROWS 
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_ROWS > 1000
                    AND TABLE_NAME NOT IN (
                        SELECT DISTINCT TABLE_NAME 
                        FROM information_schema.STATISTICS 
                        WHERE TABLE_SCHEMA = DATABASE()
                    )
                ');

                // Check for tables with excessive size
                $largeTables = DB::select('
                    SELECT TABLE_NAME, 
                           ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND ((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024) > 10
                    ORDER BY size_mb DESC
                ');
            }

            if (! empty($unindexedTables)) {
                $suggestions[] = [
                    'type' => 'indexing',
                    'priority' => 'high',
                    'message' => 'Large tables found without indexes',
                    'tables' => array_column($unindexedTables, 'TABLE_NAME'),
                ];
            }

            if (! empty($largeTables)) {
                $suggestions[] = [
                    'type' => 'storage',
                    'priority' => 'medium',
                    'message' => 'Large tables that may need optimization',
                    'tables' => $largeTables,
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return [
            'suggestions' => $suggestions,
            'total_db_size_mb' => $this->getDatabaseSize(),
            'fragmentation_check' => $this->checkTableFragmentation(),
        ];
    }

    public function checkTableFragmentation()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                return [];
            }
            $fragmentation = DB::select('
                SELECT TABLE_NAME, 
                       ROUND(DATA_FREE / 1024 / 1024, 2) as fragmentation_mb,
                       ROUND((DATA_FREE / (DATA_LENGTH + INDEX_LENGTH + DATA_FREE)) * 100, 2) as fragmentation_percent
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND DATA_FREE > 0
                ORDER BY fragmentation_mb DESC
                LIMIT 10
            ');

            return $fragmentation;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getDatabaseSize()
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                return 0.1;
            }
            $result = DB::selectOne('
                SELECT ROUND(SUM((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as size_mb
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE()
            ');

            return $result->size_mb ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function identifyPerformanceBottlenecks()
    {
        $bottlenecks = [];

        try {
            // Check for queries without pagination
            $largeResultQueries = DB::select("
                SELECT * FROM performance_metrics 
                WHERE metric_type = 'query' 
                AND JSON_EXTRACT(metadata, '$.rows_returned') > 1000
                ORDER BY created_at DESC 
                LIMIT 10
            ");

            if (! empty($largeResultQueries)) {
                $bottlenecks[] = [
                    'type' => 'database',
                    'issue' => 'Queries returning large result sets',
                    'impact' => 'high',
                    'queries' => count($largeResultQueries),
                ];
            }

            // Check for memory-intensive operations
            $memoryIssues = DB::select("
                SELECT * FROM performance_metrics 
                WHERE metric_type = 'memory' 
                AND value > 100
                ORDER BY created_at DESC 
                LIMIT 5
            ");

            if (! empty($memoryIssues)) {
                $bottlenecks[] = [
                    'type' => 'memory',
                    'issue' => 'High memory usage detected',
                    'impact' => 'medium',
                    'instances' => count($memoryIssues),
                ];
            }
        } catch (\Exception $e) {
            // Ignore if performance_metrics table does not exist
        }

        return $bottlenecks;
    }
}
