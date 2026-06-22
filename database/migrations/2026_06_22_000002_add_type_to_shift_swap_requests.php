<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `type` distinguishes a one-sided COVER (a coworker takes over the requester's
 * shift; requester gets the day off) from a two-sided SWAP (trade two specific
 * rostered shifts). Defaults to 'swap' for any legacy rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->enum('type', ['swap', 'cover'])->default('swap')->after('requester_date');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
