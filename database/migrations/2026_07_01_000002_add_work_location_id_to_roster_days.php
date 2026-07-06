<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            $table->foreignId('work_location_id')->nullable()->after('shift_id')
                ->constrained('work_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            if (Schema::hasColumn('roster_days', 'work_location_id')) {
                $table->dropForeign(['work_location_id']);
                $table->dropColumn('work_location_id');
            }
        });
    }
};
