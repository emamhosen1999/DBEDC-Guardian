<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * departments.code is display-only (nothing branches on it), so the Quality Control
 * department takes the register's code, QCD, directly. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('departments')->where('code', 'QCD')->exists()) {
            DB::table('departments')->where('name', 'Quality Control')->where('code', 'QC')->update(['code' => 'QCD']);
        }
    }

    public function down(): void
    {
        if (! DB::table('departments')->where('code', 'QC')->exists()) {
            DB::table('departments')->where('name', 'Quality Control')->where('code', 'QCD')->update(['code' => 'QC']);
        }
    }
};
