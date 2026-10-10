<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * request_logs and roster_day_changes took created_at from the column default (CURRENT_TIMESTAMP), i.e. the database
 * server's own zone (production: US Eastern), while every other table is stamped by the application in
 * config('app.timezone'). Their models now stamp created_at themselves; this converts the rows written before that,
 * once, from the server zone to the application zone (DST-aware via CONVERT_TZ 'SYSTEM').
 *
 * Only rows not later than the database's NOW() are converted: rows already written by the fixed models carry the
 * application's (later) wall clock and are left alone. MySQL/MariaDB only; other drivers have no server zone to undo.
 */
return new class extends Migration
{
    private const TABLES = ['request_logs', 'roster_day_changes'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $offset = now()->format('P'); // the application zone's current UTC offset, e.g. +06:00 (Asia/Dhaka has no DST)

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'created_at')) {
                continue;
            }
            $maxId = (int) DB::table($table)->max('id');
            // Chunks by primary key so a large log table is never locked in one long statement.
            for ($from = 0; $from <= $maxId; $from += 20000) {
                DB::update(
                    "UPDATE `{$table}` SET created_at = CONVERT_TZ(created_at, 'SYSTEM', ?) WHERE id > ? AND id <= ? AND created_at <= NOW()",
                    [$offset, $from, $from + 20000]
                );
            }
        }
    }

    public function down(): void
    {
        // Not reversible: the converted rows are now in the application zone like every other table.
    }
};
