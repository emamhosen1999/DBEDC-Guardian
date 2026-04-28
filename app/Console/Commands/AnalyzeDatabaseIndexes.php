<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyzeDatabaseIndexes extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:analyze-indexes {--create : Create recommended indexes} {--table= : Analyze specific table}';

    /**
     * The console command description.
     */
    protected $description = 'Analyze database indexes and provide optimization recommendations';

    private array $recommendations = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $specificTable = $this->option('table');
        $shouldCreate = $this->option('create');

        $this->info('🔍 Analyzing database indexes...');

        if ($specificTable) {
            $this->analyzeTable($specificTable);
        } else {
            $this->analyzeAllTables();
        }

        $this->displayRecommendations();

        if ($shouldCreate && !empty($this->recommendations)) {
            $this->createRecommendedIndexes();
        }

        return self::SUCCESS;
    }

    /**
     * Analyze all relevant tables
     */
    private function analyzeAllTables(): void
    {
        $tables = [
            'daily_works',
            'rfi_objections',
            'users',
            'jurisdictions',
            'reports',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->analyzeTable($table);
            }
        }
    }

    /**
     * Analyze a specific table's indexes
     */
    private function analyzeTable(string $table): void
    {
        $this->info("📊 Analyzing table: {$table}");

        $existingIndexes = $this->getExistingIndexes($table);
        $recommendedIndexes = $this->getRecommendedIndexes($table);

        $this->line("Current indexes: " . count($existingIndexes));
        $this->line("Recommended indexes: " . count($recommendedIndexes));

        // Check for missing indexes
        foreach ($recommendedIndexes as $indexName => $indexDef) {
            if (!isset($existingIndexes[$indexName])) {
                $this->recommendations[] = [
                    'table' => $table,
                    'index_name' => $indexName,
                    'definition' => $indexDef,
                    'reason' => $this->getIndexReason($table, $indexName),
                ];
            }
        }

        $this->newLine();
    }

    /**
     * Get existing indexes for a table
     */
    private function getExistingIndexes(string $table): array
    {
        $driver = DB::getDriverName();
        $indexes = [];

        if ($driver === 'mysql') {
            $results = DB::select("
                SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                ORDER BY INDEX_NAME, SEQ_IN_INDEX
            ", [$table]);

            foreach ($results as $result) {
                $indexName = $result->INDEX_NAME;
                if (!isset($indexes[$indexName])) {
                    $indexes[$indexName] = [];
                }
                $indexes[$indexName][] = $result->COLUMN_NAME;
            }
        } elseif ($driver === 'sqlite') {
            $results = DB::select("
                SELECT name, sql
                FROM sqlite_master
                WHERE type = 'index'
                AND tbl_name = ?
                AND name NOT LIKE 'sqlite_%'
            ", [$table]);

            foreach ($results as $result) {
                // Parse index name and columns from SQL
                if (preg_match('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(\w+)\s+ON\s+\w+\s*\(([^)]+)\)/i', $result->sql, $matches)) {
                    $indexName = $matches[1];
                    $columns = array_map('trim', explode(',', $matches[2]));
                    $indexes[$indexName] = $columns;
                }
            }
        }

        return $indexes;
    }

    /**
     * Get recommended indexes for a table
     */
    private function getRecommendedIndexes(string $table): array
    {
        $indexes = [];

        switch ($table) {
            case 'daily_works':
                $indexes = [
                    'idx_daily_works_date' => ['date'],
                    'idx_daily_works_status' => ['status'],
                    'idx_daily_works_type' => ['type'],
                    'idx_daily_works_incharge' => ['incharge'],
                    'idx_daily_works_assigned' => ['assigned'],
                    'idx_daily_works_number' => ['number'],
                    'idx_daily_works_location' => ['location(50)'], // Partial index for location
                    'idx_daily_works_date_status' => ['date', 'status'],
                    'idx_daily_works_incharge_date' => ['incharge', 'date'],
                    'idx_daily_works_status_date' => ['status', 'date'],
                ];
                break;

            case 'rfi_objections':
                $indexes = [
                    'idx_rfi_objections_status' => ['status'],
                    'idx_rfi_objections_created_by' => ['created_by'],
                    'idx_rfi_objections_category' => ['category'],
                    'idx_rfi_objections_created_at' => ['created_at'],
                    'idx_rfi_objections_status_created' => ['status', 'created_at'],
                ];
                break;

            case 'users':
                $indexes = [
                    'idx_users_email' => ['email'],
                    'idx_users_active' => ['active'],
                    'idx_users_designation' => ['designation_id'],
                ];
                break;

            case 'daily_work_objection':
                $indexes = [
                    'idx_daily_work_objection_daily_work' => ['daily_work_id'],
                    'idx_daily_work_objection_objection' => ['rfi_objection_id'],
                    'idx_daily_work_objection_attached_by' => ['attached_by'],
                    'idx_daily_work_objection_composite' => ['daily_work_id', 'rfi_objection_id'],
                ];
                break;
        }

        return $indexes;
    }

    /**
     * Get reason for recommending an index
     */
    private function getIndexReason(string $table, string $indexName): string
    {
        $reasons = [
            'daily_works' => [
                'idx_daily_works_date' => 'Frequently filtered by date ranges',
                'idx_daily_works_status' => 'Status filtering in dashboards and reports',
                'idx_daily_works_type' => 'Work type filtering and analytics',
                'idx_daily_works_incharge' => 'User-based filtering for supervisors',
                'idx_daily_works_assigned' => 'User-based filtering for assignees',
                'idx_daily_works_number' => 'Primary search field and lookups',
                'idx_daily_works_location' => 'Location-based search and filtering',
                'idx_daily_works_date_status' => 'Combined date and status queries',
                'idx_daily_works_incharge_date' => 'Supervisor dashboard queries',
                'idx_daily_works_status_date' => 'Status trend analysis queries',
            ],
            'rfi_objections' => [
                'idx_rfi_objections_status' => 'Objection status filtering',
                'idx_rfi_objections_created_by' => 'User-based objection queries',
                'idx_rfi_objections_category' => 'Category-based filtering',
                'idx_rfi_objections_created_at' => 'Time-based objection queries',
                'idx_rfi_objections_status_created' => 'Status and time combined queries',
            ],
        ];

        return $reasons[$table][$indexName] ?? 'Performance optimization for common queries';
    }

    /**
     * Display recommendations
     */
    private function displayRecommendations(): void
    {
        if (empty($this->recommendations)) {
            $this->info('✅ All recommended indexes are already present!');
            return;
        }

        $this->newLine();
        $this->warn('📋 Recommended Indexes:');

        $table = $this->table(['Table', 'Index Name', 'Reason'], array_map(function ($rec) {
            return [
                $rec['table'],
                $rec['index_name'],
                $rec['reason'],
            ];
        }, $this->recommendations));

        $this->newLine();
        $this->info("Found " . count($this->recommendations) . " missing indexes");
    }

    /**
     * Create recommended indexes
     */
    private function createRecommendedIndexes(): void
    {
        if (!$this->confirm('Do you want to create the recommended indexes? This may take some time.')) {
            return;
        }

        $this->info('🔨 Creating recommended indexes...');

        $progressBar = $this->output->createProgressBar(count($this->recommendations));
        $progressBar->start();

        $created = 0;
        foreach ($this->recommendations as $rec) {
            try {
                $this->createIndex($rec['table'], $rec['index_name'], $rec['definition']);
                $created++;
            } catch (\Exception $e) {
                $this->error("Failed to create index {$rec['index_name']}: " . $e->getMessage());
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("✅ Created {$created} indexes successfully!");
    }

    /**
     * Create a single index
     */
    private function createIndex(string $table, string $indexName, $definition): void
    {
        if (is_array($definition)) {
            $columnsList = implode(', ', $definition);
        } else {
            $columnsList = $definition;
        }

        $sql = "CREATE INDEX {$indexName} ON {$table} ({$columnsList})";

        // Add database-specific optimizations
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $sql = "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} ({$columnsList})";
        }

        try {
            DB::statement($sql);
        } catch (\Exception $e) {
            // Log but continue - index might already exist
            $this->warn("Could not create index {$indexName}: " . $e->getMessage());
        }
    }
}