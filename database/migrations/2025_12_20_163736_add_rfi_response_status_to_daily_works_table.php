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
        Schema::table('daily_works', function (Blueprint $table) {
            $table->string('rfi_response_status')->nullable()->after('rfi_submission_date');
            $table->date('rfi_response_date')->nullable()->after('rfi_response_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_works', function (Blueprint $table) {
            $table->dropColumn(['rfi_response_status', 'rfi_response_date']);
        });
    }
};
