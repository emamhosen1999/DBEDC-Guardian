<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('offboardings', 'lwd_processed_at')) {
            Schema::table('offboardings', function (Blueprint $table) {
                // Set once the LWD effects (access revocation, biometric removal,
                // roster cleanup) have run; makes ProcessOffboardingLwd idempotent.
                $table->timestamp('lwd_processed_at')->nullable();
                $table->index(['last_working_date', 'lwd_processed_at'], 'offboardings_lwd_sweep_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('offboardings', 'lwd_processed_at')) {
            Schema::table('offboardings', function (Blueprint $table) {
                $table->dropIndex('offboardings_lwd_sweep_index');
                $table->dropColumn('lwd_processed_at');
            });
        }
    }
};
