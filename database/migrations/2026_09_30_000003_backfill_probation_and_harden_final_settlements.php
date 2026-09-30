<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKFILL_NOTE = 'Backfilled: joined before probation tracking was introduced (2026-09-30).';

    public function up(): void
    {
        $this->backfillProbation();
        $this->hardenFinalSettlements();
    }

    /**
     * 2026_09_29_000002 defaulted every existing employee to 'probationary'.
     * Idempotent: only rows still matching the bad default are touched.
     */
    private function backfillProbation(): void
    {
        if (! Schema::hasColumn('users', 'employment_status')) {
            return;
        }

        $cutoff = now()->subMonths(6)->toDateString();

        // Joined 6+ months ago and never explicitly confirmed => long-standing staff.
        DB::table('users')
            ->where('employment_status', 'probationary')
            ->whereNull('confirmation_date')
            ->whereNotNull('date_of_joining')
            ->whereDate('date_of_joining', '<=', $cutoff)
            ->orderBy('employee_id')
            ->chunkById(200, function ($users) {
                DB::table('users')
                    ->whereIn('employee_id', $users->pluck('employee_id'))
                    ->where('employment_status', 'probationary')
                    ->update([
                        'employment_status' => 'confirmed',
                        'probation_notes' => self::BACKFILL_NOTE,
                    ]);
            }, 'employee_id');

        // Genuinely on probation: BLA 2006 s.4(7) clerical probation is 6 months
        // (s.4(8) allows a further 3-month extension, applied manually per case).
        DB::table('users')
            ->where('employment_status', 'probationary')
            ->whereNotNull('date_of_joining')
            ->whereNull('probation_end_date')
            ->orderBy('employee_id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('employee_id', $user->employee_id)
                        ->whereNull('probation_end_date')
                        ->update([
                            'probation_end_date' => Carbon::parse($user->date_of_joining)->addMonths(6)->toDateString(),
                        ]);
                }
            }, 'employee_id');
    }

    private function hardenFinalSettlements(): void
    {
        if (! Schema::hasTable('final_settlements')) {
            return;
        }

        // Financial records must never be erased by a parent delete.
        // SQLite (tests) cannot alter FKs in place; the original definition stays there.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('final_settlements', function (Blueprint $table) {
                $table->dropForeign(['offboarding_id']);
                $table->dropForeign(['employee_id']);
            });
            Schema::table('final_settlements', function (Blueprint $table) {
                $table->foreign('offboarding_id')->references('id')->on('offboardings')->restrictOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('users')->restrictOnDelete();
            });
        }

        // One settlement per offboarding (updateOrCreate race). Skip if legacy duplicates exist.
        $duplicates = DB::table('final_settlements')
            ->select('offboarding_id')
            ->groupBy('offboarding_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('offboarding_id');

        if ($duplicates->isNotEmpty()) {
            Log::warning('final_settlements: duplicate offboarding_id rows exist; unique index NOT created. Resolve manually.', [
                'offboarding_ids' => $duplicates->all(),
            ]);

            return;
        }

        if (! $this->hasIndex('final_settlements', 'final_settlements_offboarding_id_unique')) {
            Schema::table('final_settlements', function (Blueprint $table) {
                $table->unique('offboarding_id');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);
    }

    public function down(): void
    {
        if (Schema::hasTable('final_settlements')
            && $this->hasIndex('final_settlements', 'final_settlements_offboarding_id_unique')) {
            Schema::table('final_settlements', function (Blueprint $table) {
                $table->dropUnique(['offboarding_id']);
            });
        }

        if (Schema::hasTable('final_settlements') && DB::getDriverName() !== 'sqlite') {
            Schema::table('final_settlements', function (Blueprint $table) {
                $table->dropForeign(['offboarding_id']);
                $table->dropForeign(['employee_id']);
            });
            Schema::table('final_settlements', function (Blueprint $table) {
                $table->foreign('offboarding_id')->references('id')->on('offboardings')->cascadeOnDelete();
                $table->foreign('employee_id')->references('employee_id')->on('users')->cascadeOnDelete();
            });
        }
        // The probation backfill is a data correction and is not reversed.
    }
};
