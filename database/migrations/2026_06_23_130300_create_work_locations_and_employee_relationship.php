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
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('attendance_type_id')->nullable()->constrained('attendance_types')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('work_location_id')->nullable()->after('report_to')->constrained('work_locations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'work_location_id')) {
                $table->dropForeign(['work_location_id']);
                $table->dropColumn('work_location_id');
            }
        });

        Schema::dropIfExists('work_locations');
    }
};
