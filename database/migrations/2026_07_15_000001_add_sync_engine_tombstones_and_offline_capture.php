<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening for the mobile delta-sync engine.
 *
 *  1. `sync_tombstones` — an append-only deletions log so a hard-deleted (or
 *     otherwise removed) row can be surfaced to already-synced devices as a
 *     `{id, deleted:true}` tombstone during pull. Without this, deletes live
 *     forever on the client (bug H6 / S3).
 *
 *  2. `attendances.was_offline` — flags a punch that was captured offline and
 *     replayed through the bounded sync channel with a client `captured_at`.
 *     Keeps the audit trail honest: the punch is *attributed* to the real
 *     capture moment but *marked* as device-asserted (bug S4).
 *
 *  3. Pull-path indexes on `updated_at` so the delta cursor scan is not a full
 *     table scan at scale (S7).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_tombstones')) {
            Schema::create('sync_tombstones', function (Blueprint $table) {
                // Auto-increment id IS the monotonic ordering key the pull cursor pages on.
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('module', 50);
                $table->unsignedBigInteger('entity_id');
                $table->timestamp('created_at')->nullable();

                // Pull predicate: WHERE user_id = ? AND module = ? AND id > ? ORDER BY id ASC.
                $table->index(['user_id', 'module', 'id'], 'sync_tombstones_scope_cursor_index');
            });
        }

        if (Schema::hasTable('attendances') && ! Schema::hasColumn('attendances', 'was_offline')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->boolean('was_offline')->default(false)->after('punchout_location');
            });
        }

        // Pull cursor scans order by (updated_at, id); index updated_at so the delta
        // read is range-scannable rather than a full table scan.
        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'updated_at')) {
            $this->addIndexIfMissing('attendances', 'updated_at', 'attendances_updated_at_index');
        }

        if (Schema::hasTable('daily_works') && Schema::hasColumn('daily_works', 'updated_at')) {
            $this->addIndexIfMissing('daily_works', 'updated_at', 'daily_works_updated_at_index');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'was_offline')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('was_offline');
            });
        }

        $this->dropIndexIfExists('attendances', 'attendances_updated_at_index');
        $this->dropIndexIfExists('daily_works', 'daily_works_updated_at_index');

        Schema::dropIfExists('sync_tombstones');
    }

    private function addIndexIfMissing(string $table, string $column, string $indexName): void
    {
        // Idempotent by NAME rather than by swallowing every exception. The old
        // catch-all `catch (\Throwable)` hid genuine failures (a bad column, a
        // key-too-long error) behind the same silence as a benign "already
        // exists", letting the migration report success while doing nothing.
        if ($this->hasIndexNamed($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName) {
            $blueprint->index($column, $indexName);
        });

        // Verify the index is really there before returning.
        if (! $this->hasIndexNamed($table, $indexName)) {
            throw new RuntimeException(
                "add_sync_engine_tombstones: index [{$indexName}] on [{$table}] was not created."
            );
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! $this->hasIndexNamed($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    private function hasIndexNamed(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strtolower((string) $index['name']) === strtolower($indexName)) {
                return true;
            }
        }

        return false;
    }
};
