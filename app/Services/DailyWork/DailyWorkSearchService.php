<?php

namespace App\Services\DailyWork;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DailyWorkSearchService
{
    /**
     * Apply optimized search to the query
     */
    public function applySearch(Builder $query, string $searchTerm): Builder
    {
        if (empty(trim($searchTerm))) {
            return $query;
        }

        // Split search into words and clean them
        $words = $this->parseSearchWords($searchTerm);

        if (empty($words)) {
            return $query;
        }

        // Check if we can use full-text search (MySQL/PostgreSQL)
        if ($this->supportsFullTextSearch()) {
            return $this->applyFullTextSearch($query, $words);
        }

        // Fallback to optimized LIKE search
        return $this->applyOptimizedLikeSearch($query, $words);
    }

    /**
     * Parse search words from search term
     */
    private function parseSearchWords(string $searchTerm): array
    {
        // Split by whitespace and filter out empty strings
        $words = array_filter(preg_split('/\s+/', trim($searchTerm)));

        // Remove words shorter than 2 characters (they're not useful for search)
        return array_filter($words, fn($word) => strlen($word) >= 2);
    }

    /**
     * Check if database supports full-text search
     */
    private function supportsFullTextSearch(): bool
    {
        $driver = DB::getDriverName();

        // MySQL and PostgreSQL support full-text search
        return in_array($driver, ['mysql', 'pgsql'], true);
    }

    /**
     * Apply full-text search for supported databases
     */
    private function applyFullTextSearch(Builder $query, array $words): Builder
    {
        $searchTerm = implode(' ', $words);

        return $query->where(function ($q) use ($searchTerm) {
            // Search across multiple text columns
            $q->whereRaw("MATCH(number, location, description, type, side, inspection_details) AGAINST(? IN NATURAL LANGUAGE MODE)", [$searchTerm])
              ->orWhereRaw("MATCH(number, location, description) AGAINST(? IN BOOLEAN MODE)", [$searchTerm]);
        });
    }

    /**
     * Apply optimized LIKE search for databases without full-text support
     */
    private function applyOptimizedLikeSearch(Builder $query, array $words): Builder
    {
        // Define searchable columns with their priorities
        $searchableColumns = [
            'number',           // High priority - exact matches
            'location',         // High priority - specific locations
            'description',      // Medium priority - detailed text
            'type',            // Low priority - enum values
            'side',            // Low priority - enum values
            'inspection_details', // Low priority - long text
        ];

        return $query->where(function ($query) use ($words, $searchableColumns) {
            foreach ($words as $word) {
                $query->where(function ($wordQuery) use ($word, $searchableColumns) {
                    foreach ($searchableColumns as $column) {
                        // Use case-insensitive search
                        $wordQuery->orWhere($column, 'ILIKE', "%{$word}%");
                    }
                });
            }
        });
    }

    /**
     * Create database indexes for better search performance
     * This method should be called during migration or setup
     */
    public static function createSearchIndexes(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // Create full-text indexes for MySQL
            try {
                DB::statement('
                    ALTER TABLE daily_works
                    ADD FULLTEXT INDEX idx_daily_works_search
                    (number, location, description, type, side, inspection_details)
                ');
            } catch (\Exception $e) {
                // Index might already exist, ignore
            }
        } elseif ($driver === 'pgsql') {
            // Create GIN indexes for PostgreSQL
            try {
                DB::statement('
                    CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_daily_works_search
                    ON daily_works
                    USING GIN (to_tsvector(\'english\', number || \' \' || location || \' \' || description || \' \' || type || \' \' || side || \' \' || COALESCE(inspection_details, \'\')));
                ');
            } catch (\Exception $e) {
                // Index might already exist, ignore
            }
        }

        // Create regular indexes for better LIKE performance
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_daily_works_number ON daily_works (number)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_daily_works_location ON daily_works (location)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_daily_works_type ON daily_works (type)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_daily_works_status ON daily_works (status)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_daily_works_date ON daily_works (date)');
        } catch (\Exception $e) {
            // Indexes might already exist, ignore
        }
    }

    /**
     * Search suggestions based on partial input
     */
    public function getSearchSuggestions(string $partial, int $limit = 10): array
    {
        if (strlen($partial) < 2) {
            return [];
        }

        $results = DB::table('daily_works')
            ->select('number', 'location', 'description', 'type')
            ->where(function ($query) use ($partial) {
                $query->where('number', 'ILIKE', "%{$partial}%")
                      ->orWhere('location', 'ILIKE', "%{$partial}%")
                      ->orWhere('description', 'ILIKE', "%{$partial}%");
            })
            ->limit($limit)
            ->get();

        return $results->map(function ($item) {
            return [
                'text' => $item->number . ' - ' . $item->location,
                'value' => $item->number,
                'type' => 'daily_work',
            ];
        })->toArray();
    }
}