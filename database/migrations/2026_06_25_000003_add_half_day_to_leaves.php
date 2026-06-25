<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }

        Schema::table('leaves', function (Blueprint $table) {
            if (! Schema::hasColumn('leaves', 'is_half_day')) {
                $table->boolean('is_half_day')->default(false)->after('no_of_days');
            }
            if (! Schema::hasColumn('leaves', 'half_day_session')) {
                $table->string('half_day_session', 16)->nullable()->after('is_half_day');
            }
        });

        // Widen no_of_days to hold halves. sqlite is typeless (no-op there);
        // MySQL needs the explicit change. Guard so sqlite tests don't choke.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('leaves', function (Blueprint $table) {
                $table->decimal('no_of_days', 5, 1)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }
        Schema::table('leaves', function (Blueprint $table) {
            foreach (['is_half_day', 'half_day_session'] as $col) {
                if (Schema::hasColumn('leaves', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
