<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A manual roster adjustment is PINNED by `source = manual` (RosterService::generateRoster() never
 * overwrites it); `locked` now means only "finalized" (e.g. set by an approved swap). Manual cells
 * used to be saved locked as well, which made them uneditable for the roster manager who made them.
 * Idempotent: only manual rows that are still locked are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $count = DB::table('roster_days')->where('source', 'manual')->where('locked', true)->update(['locked' => false]);
        Log::info('Unlocked manual roster cells (pinned by source, not locked)', ['rows' => $count]);
    }

    public function down(): void
    {
        // Not reversible: which manual rows were locked before is not recorded.
    }
};
