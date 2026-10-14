<?php

use App\Services\DailyWork\DailyWorkChainageBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * daily_works.start_m / end_m: the numeric chainage of `location`, indexed, so range filters are indexed range queries.
 * daily_works.jurisdiction_id: the jurisdiction the work belonged to ON ITS DATE (resolved against the versions), so a later
 * boundary or in-charge change never rewrites history; the work's own incharge / assigned stay as recorded. Backfilled in chunks by DailyWorkChainageBackfill (also
 * `php artisan daily-works:backfill-chainage`), idempotent, with the unreadable locations reported and left NULL.
 * The model keeps both in sync from here on (HasChainageMetres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_works', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_works', 'start_m')) {
                $table->unsignedInteger('start_m')->nullable()->after('location');
                $table->index('start_m', 'daily_works_start_m_index');
            }
            if (! Schema::hasColumn('daily_works', 'end_m')) {
                $table->unsignedInteger('end_m')->nullable()->after('start_m');
                $table->index('end_m', 'daily_works_end_m_index');
            }
            if (! Schema::hasColumn('daily_works', 'jurisdiction_id')) {
                $table->unsignedBigInteger('jurisdiction_id')->nullable()->after('end_m');
                $table->index(['jurisdiction_id', 'date'], 'daily_works_jurisdiction_date_index');
            }
        });

        $report = app(DailyWorkChainageBackfill::class)->run();
        Log::info('daily_works chainage backfill', ['scanned' => $report['scanned'], 'filled' => $report['filled'], 'unparseable' => $report['unparseable_rows'], 'unattributed' => $report['unattributed']]);
    }

    public function down(): void
    {
        Schema::table('daily_works', function (Blueprint $table) {
            if (Schema::hasColumn('daily_works', 'start_m')) {
                $table->dropIndex('daily_works_start_m_index');
            }
            if (Schema::hasColumn('daily_works', 'end_m')) {
                $table->dropIndex('daily_works_end_m_index');
            }
            if (Schema::hasColumn('daily_works', 'jurisdiction_id')) {
                $table->dropIndex('daily_works_jurisdiction_date_index');
            }
        });
        Schema::table('daily_works', function (Blueprint $table) {
            $columns = array_values(array_filter(['start_m', 'end_m', 'jurisdiction_id'], fn (string $c): bool => Schema::hasColumn('daily_works', $c)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
