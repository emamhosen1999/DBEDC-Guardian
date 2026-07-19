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
            // Only add the index if NOTHING already covers `user_id` as a leading
            // column (the FK index `leaves_user_id_foreign` normally does). This
            // replaces the old catch-all try/catch that swallowed every error —
            // including real ones — with an explicit, verifiable pre-check so a
            // genuine failure can no longer masquerade as success.
            if (! $this->hasIndexLeadingWith('leaves', 'user_id')) {
                Schema::table('leaves', function (Blueprint $table) {
                    $table->index('user_id', 'leaves_user_id_index');
                });

                // Verify the create actually took effect.
                if (! $this->hasIndexLeadingWith('leaves', 'user_id')) {
                    throw new RuntimeException(
                        'ensure_user_id_on_leaves_table: leaves.user_id index was not created.'
                    );
                }
            }
        }
    }

    /**
     * Does any index on $table start with $column as its first column?
     */
    private function hasIndexLeadingWith(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = array_map('strtolower', $index['columns'] ?? []);
            if (($columns[0] ?? null) === strtolower($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid destructive schema rollback in existing environments.
    }
};
