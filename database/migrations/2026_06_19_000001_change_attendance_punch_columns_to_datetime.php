<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Snapshot existing TIME values combined with `date` into DATETIME strings.
        // Read raw rows BEFORE altering the column type.
        $rows = DB::table('attendances')->select('id', 'date', 'punchin', 'punchout')->get();

        // 2. Alter column types.
        Schema::table('attendances', function (Blueprint $table) {
            $table->dateTime('punchin')->nullable()->change();
            $table->dateTime('punchout')->nullable()->change();
        });

        if (! $this->indexExists('attendances', 'attendances_user_id_date_index')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['user_id', 'date']);
            });
        }

        // 3. Backfill: combine the business `date` with the old time-of-day.
        foreach ($rows as $row) {
            $update = [];
            $date = Carbon::parse($row->date)->format('Y-m-d');

            foreach (['punchin', 'punchout'] as $col) {
                if (empty($row->$col)) {
                    continue;
                }
                // Old value may be "HH:MM:SS" (TIME) — combine with date.
                // If it already parses as a full datetime, keep it.
                $raw = (string) $row->$col;
                $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw)
                    ? $raw
                    : Carbon::parse($raw)->format('H:i:s');
                $update[$col] = $date.' '.$time;
            }

            if ($update) {
                DB::table('attendances')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Note: the (user_id, date) index is owned by an earlier migration
        // (2026_06_02_120000_add_indexes_for_scalability.php); it predates this
        // migration in most environments, so it is intentionally left untouched here.
        Schema::table('attendances', function (Blueprint $table) {
            $table->time('punchin')->nullable()->change();
            $table->time('punchout')->nullable()->change();
        });
    }

    /**
     * Determine whether the given index already exists on the table.
     * Driver-agnostic check (works on MySQL and sqlite).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
