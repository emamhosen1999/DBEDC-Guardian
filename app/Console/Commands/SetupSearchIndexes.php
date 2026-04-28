<?php

namespace App\Console\Commands;

use App\Services\DailyWork\DailyWorkSearchService;
use Illuminate\Console\Command;

class SetupSearchIndexes extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'search:setup-indexes {--force : Force recreation of existing indexes}';

    /**
     * The console command description.
     */
    protected $description = 'Create database indexes for optimized search performance';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = $this->option('force');

        if ($force) {
            $this->info('🔄 Force recreating search indexes...');
        } else {
            $this->info('🚀 Setting up search indexes for optimal performance...');
        }

        $startTime = microtime(true);

        try {
            // Create the search indexes
            DailyWorkSearchService::createSearchIndexes();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("✅ Search indexes created successfully in {$duration}ms");
            $this->newLine();

            $this->comment('Created indexes:');
            $this->line('• Full-text search index (MySQL/PostgreSQL)');
            $this->line('• Individual column indexes for fast filtering');
            $this->line('• Composite indexes for common query patterns');

            $this->newLine();
            $this->info('💡 Note: Search performance will be significantly improved!');
            $this->info('   Consider running this command after large data imports.');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Failed to create search indexes: ' . $e->getMessage());
            $this->line('Stack trace: ' . $e->getTraceAsString());

            return self::FAILURE;
        }
    }
}