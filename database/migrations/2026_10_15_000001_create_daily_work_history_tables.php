<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily works are historical. Three append-only tables and one provenance column:
 *
 *  - daily_work_revisions: every change to a work, field by field (before and after), who, when and from where
 *    (web, import, api, mobile, console). Numbered per work and linked to the previous revision. Never updated or deleted.
 *  - daily_work_status_events: each status / inspection-result transition as a timestamped event. The source of the
 *    turnaround and cycle-time metrics.
 *  - daily_work_import_batches: one row per Excel import (the file's hash, counts, the error report) so a batch can be
 *    reviewed and, with its own permission, reversed as an audited soft action.
 *  - daily_works.import_batch_id: the batch that created a work.
 *
 * Existing works get no invented history: the timeline says when recording began. New tables are InnoDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_work_import_batches')) {
            Schema::create('daily_work_import_batches', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->uuid('token')->unique();
                $table->string('filename', 255);
                $table->char('file_sha256', 64)->index();
                $table->string('status', 16)->default('previewed')->index(); // previewed | committed | reversed | failed
                $table->unsignedSmallInteger('sheets')->default(0);
                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('rows_valid')->default(0);
                $table->unsignedInteger('rows_warning')->default(0);
                $table->unsignedInteger('rows_error')->default(0);
                $table->unsignedInteger('rows_created')->default(0);
                $table->unsignedInteger('rows_resubmitted')->default(0);
                $table->unsignedInteger('rows_skipped')->default(0);
                $table->json('summary')->nullable();
                $table->string('created_by', 50)->nullable();
                $table->timestamp('committed_at')->nullable();
                $table->string('reversed_by', 50)->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->string('reverse_reason', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('daily_work_revisions')) {
            Schema::create('daily_work_revisions', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('daily_work_id');
                $table->unsignedInteger('revision_no');
                $table->unsignedBigInteger('previous_revision_id')->nullable();
                $table->string('event', 24); // created | updated | status_changed | reassigned | resubmitted | deleted | restored | import_reversed
                $table->json('tags')->nullable();
                $table->json('changes');
                $table->string('source', 12)->default('web'); // web | import | api | mobile | console
                $table->string('actor_id', 50)->nullable()->index();
                $table->unsignedBigInteger('import_batch_id')->nullable()->index();
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['daily_work_id', 'revision_no']);
                $table->index(['event', 'created_at']);
            });
        }

        if (! Schema::hasTable('daily_work_status_events')) {
            Schema::create('daily_work_status_events', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('daily_work_id');
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->string('from_result', 16)->nullable();
                $table->string('to_result', 16)->nullable();
                $table->string('actor_id', 50)->nullable()->index();
                $table->string('source', 12)->default('web');
                $table->unsignedBigInteger('import_batch_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamp('occurred_at')->useCurrent();

                $table->index(['daily_work_id', 'occurred_at']);
                $table->index(['to_status', 'occurred_at']);
            });
        }

        if (! Schema::hasColumn('daily_works', 'import_batch_id')) {
            Schema::table('daily_works', function (Blueprint $table) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('jurisdiction_id');
                $table->index('import_batch_id', 'daily_works_import_batch_index');
            });
        }

        // 2026_09_02_000002 made daily_works.assigned a nullable varchar(50) on MySQL only; SQLite (the test database) kept
        // the original NOT NULL foreign id. Production holds 18.7k works with no assignee, so match it here.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('daily_works', fn (Blueprint $table) => $table->string('assigned', 50)->nullable()->change());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('daily_works', 'import_batch_id')) {
            Schema::table('daily_works', function (Blueprint $table) {
                $table->dropIndex('daily_works_import_batch_index');
            });
            Schema::table('daily_works', function (Blueprint $table) {
                $table->dropColumn('import_batch_id');
            });
        }
        Schema::dropIfExists('daily_work_status_events');
        Schema::dropIfExists('daily_work_revisions');
        Schema::dropIfExists('daily_work_import_batches');
    }
};
