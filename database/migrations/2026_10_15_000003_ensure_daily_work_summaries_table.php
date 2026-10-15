<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-day, per-in-charge counters table existed only on databases created by hand; no migration built it, so a fresh
 * install (and the test database) had no table for the import to maintain. Create it when it is missing; leave an existing one alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_work_summaries')) {
            return;
        }

        Schema::create('daily_work_summaries', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('incharge');
            $table->unsignedInteger('totalDailyWorks')->default(0);
            $table->unsignedInteger('resubmissions')->default(0);
            $table->unsignedInteger('embankment')->default(0);
            $table->unsignedInteger('structure')->default(0);
            $table->unsignedInteger('pavement')->default(0);
            $table->timestamps();
            $table->index(['date', 'incharge'], 'daily_work_summaries_date_incharge_index');
        });
    }

    public function down(): void
    {
        // Intentionally left in place: the table may predate this migration and hold production counters.
    }
};
