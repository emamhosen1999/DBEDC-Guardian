<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user monotonic sync epoch for the mobile delta-sync engine.
 *
 * WHY: `pull` returns only currently-visible rows plus per-row deletion
 * tombstones. When a USER'S OWN scope inputs change — report_to, department_id,
 * designation_id, or their Spatie role set — their ENTIRE daily-work visibility
 * window can shift wholesale with NO per-row event to tombstone. Emitting per-row
 * tombstones for a whole-set shift is unbounded, so instead the user's epoch is
 * bumped and their device is forced to RE-BOOTSTRAP on its next pull (it presents
 * its last-known epoch; a stale epoch earns a reset directive).
 *
 * NO INDEX ON PURPOSE: the value is only ever read for the already-resolved
 * authenticated user (by primary key) and incremented by that same key — it is
 * never queried across users — so an index would only cost writes and buy nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'sync_epoch')) {
            Schema::table('users', function (Blueprint $table) {
                // Starts at 1 so a brand-new user is never "behind" epoch 0.
                $table->unsignedInteger('sync_epoch')->default(1)->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'sync_epoch')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sync_epoch');
            });
        }
    }
};
