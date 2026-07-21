<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the shift-swap `type` enum with 'pickup' — the mirror of a cover: the
 * requester TAKES a counterparty's shift (they give nothing up). Without this the
 * enum CHECK constraint (SQLite) / ENUM definition (MySQL) rejects a pickup row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite stores enum as varchar + a CHECK constraint; rebuild the
            // column via Laravel's native change() so the new value is allowed.
            Schema::table('shift_swap_requests', function (Blueprint $table) {
                $table->enum('type', ['swap', 'cover', 'pickup'])->default('swap')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE shift_swap_requests MODIFY COLUMN type ENUM('swap', 'cover', 'pickup') NOT NULL DEFAULT 'swap'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('shift_swap_requests', function (Blueprint $table) {
                $table->enum('type', ['swap', 'cover'])->default('swap')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE shift_swap_requests MODIFY COLUMN type ENUM('swap', 'cover') NOT NULL DEFAULT 'swap'");
    }
};
