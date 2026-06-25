<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holidays') && ! Schema::hasColumn('holidays', 'deleted_at')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('holidays') && Schema::hasColumn('holidays', 'deleted_at')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
