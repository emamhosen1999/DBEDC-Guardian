<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }

        if (! Schema::hasColumn('leaves', 'user_id')) {
            Schema::table('leaves', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable();
            });
        }

        if (Schema::hasColumn('leaves', 'user') && Schema::hasColumn('leaves', 'user_id')) {
            DB::table('leaves')
                ->select('id', 'user')
                ->orderBy('id')
                ->chunk(200, function ($leaves): void {
                    foreach ($leaves as $leave) {
                        DB::table('leaves')
                            ->where('id', $leave->id)
                            ->whereNull('user_id')
                            ->update(['user_id' => $leave->user]);
                    }
                });
        }

        if (Schema::hasColumn('leaves', 'user_id')) {
            try {
                Schema::table('leaves', function (Blueprint $table) {
                    $table->index('user_id');
                });
            } catch (\Throwable $exception) {
                // Ignore index creation errors for existing indexes.
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid destructive schema rollback in existing environments.
    }
};
