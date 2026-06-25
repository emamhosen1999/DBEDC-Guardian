<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leaves') || ! Schema::hasColumn('leaves', 'user_id')) {
            return;
        }

        // Safety: never attempt an FK add over orphan/null rows (would fail on MySQL).
        $orphans = DB::table('leaves')
            ->leftJoin('users', 'leaves.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->count();
        if ($orphans > 0) {
            throw new RuntimeException("Refusing to add FK: {$orphans} orphan leaves.user_id rows. Backfill first.");
        }

        $driver = Schema::getConnection()->getDriverName();

        // Detect an existing FK to stay idempotent (MySQL information_schema).
        $hasUserFk = false;
        if ($driver === 'mysql') {
            $hasUserFk = ! empty(DB::select(
                'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_NAME = "leaves" AND COLUMN_NAME = "user_id"
                 AND REFERENCED_TABLE_NAME = "users" AND TABLE_SCHEMA = DATABASE() LIMIT 1'
            ));
        }

        if (! $hasUserFk) {
            Schema::table('leaves', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leaves')) {
            try {
                Schema::table('leaves', function (Blueprint $table) {
                    $table->dropForeign(['user_id']);
                });
            } catch (\Throwable $e) {
                // FK may not exist; ignore.
            }
        }
    }
};
