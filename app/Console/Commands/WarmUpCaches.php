<?php

namespace App\Console\Commands;

use App\Services\Cache\ReferenceDataCacheService;
use Illuminate\Console\Command;

class WarmUpCaches extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cache:warmup {--force : Force cache refresh even if already cached}';

    /**
     * The console command description.
     */
    protected $description = 'Warm up application caches for better performance';

    private ReferenceDataCacheService $cacheService;

    public function __construct(ReferenceDataCacheService $cacheService)
    {
        parent::__construct();
        $this->cacheService = $cacheService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = $this->option('force');

        if ($force) {
            $this->info('🔄 Force refreshing all caches...');
            $this->cacheService->clearAllCaches();
        }

        $this->info('🚀 Warming up application caches...');

        $startTime = microtime(true);

        try {
            $this->cacheService->warmUpCaches();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("✅ Cache warmup completed in {$duration}ms");
            $this->newLine();

            $this->comment('Cached data includes:');
            $this->line('• Daily Work statuses, types, and sides');
            $this->line('• Inspection results and RFI response statuses');
            $this->line('• Jurisdiction data for location validation');
            $this->line('• RFI Objection categories and statuses');
            $this->line('• Active user roles for permission checks');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Cache warmup failed: ' . $e->getMessage());
            $this->line('Stack trace: ' . $e->getTraceAsString());

            return self::FAILURE;
        }
    }
}