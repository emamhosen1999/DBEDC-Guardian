<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('petty_cash_loans', function (Blueprint $table) {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE petty_cash_loans MODIFY COLUMN status ENUM('pending_approval', 'approved', 'rejected', 'active', 'closed', 'settled') NOT NULL DEFAULT 'pending_approval'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('petty_cash_loans', function (Blueprint $table) {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE petty_cash_loans MODIFY COLUMN status ENUM('active', 'closed', 'settled') NOT NULL DEFAULT 'active'");
        });
    }
};
