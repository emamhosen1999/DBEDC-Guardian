<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('effective_from');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedInteger('grace_in_minutes')->default(0);
            $table->unsignedInteger('grace_out_minutes')->default(0);
            $table->unsignedInteger('full_day_minutes')->default(0);
            $table->unsignedInteger('half_day_minutes')->default(0);
            $table->unsignedInteger('min_present_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->timestamps();

            $table->unique(['shift_id', 'effective_from']);
        });

        $this->backfillSentinelVersions();
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_versions');
    }

    /**
     * Every existing shift gets one "since forever" version row so
     * Shift::versionFor() has a real record to fall back on for all
     * historical dates, instead of relying on the model-level fallback.
     */
    private function backfillSentinelVersions(): void
    {
        $now = now();

        DB::table('shifts')
            ->orderBy('id')
            ->select([
                'id', 'start_time', 'end_time', 'crosses_midnight', 'grace_in_minutes',
                'grace_out_minutes', 'full_day_minutes', 'half_day_minutes',
                'min_present_minutes', 'break_minutes',
            ])
            ->chunkById(200, function ($shifts) use ($now) {
                $rows = [];
                foreach ($shifts as $shift) {
                    $rows[] = [
                        'shift_id' => $shift->id,
                        'effective_from' => '2000-01-01',
                        'start_time' => $shift->start_time,
                        'end_time' => $shift->end_time,
                        'crosses_midnight' => $shift->crosses_midnight,
                        'grace_in_minutes' => $shift->grace_in_minutes,
                        'grace_out_minutes' => $shift->grace_out_minutes,
                        'full_day_minutes' => $shift->full_day_minutes,
                        'half_day_minutes' => $shift->half_day_minutes,
                        'min_present_minutes' => $shift->min_present_minutes,
                        'break_minutes' => $shift->break_minutes,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! empty($rows)) {
                    DB::table('shift_versions')->insert($rows);
                }
            });
    }
};
