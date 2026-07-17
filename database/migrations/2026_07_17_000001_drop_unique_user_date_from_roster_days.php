<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-shift roster cells: the Monitoring Center legitimately double-rosters
 * nights (two officers, or one officer working day+night). Drop the
 * (user_id, date) unique constraint so a day can carry N roster_days rows.
 *
 * The OFF-day invariant (a NULL shift_id row must be the ONLY row for that
 * user+date) is enforced in the application write path (RosterController),
 * not the database, since a plain unique index can't express "unique unless
 * shift_id differs".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'date']);
            $table->unique(['user_id', 'date']);
        });
    }
};
