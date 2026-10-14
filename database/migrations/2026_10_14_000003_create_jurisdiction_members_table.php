<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The team of each jurisdiction, with history. A row is a period of holding a role: `effective_to` NULL is the current
 * row; changing a role closes the row and opens a new one (rows are never deleted).
 *
 * Dates are inclusive Asia/Dhaka calendar dates and may be in the future (a planned handover takes effect by date range,
 * never by a flag). On any date at most one in-charge row covers it; that is enforced by the service, and two guards back it
 * for the open-ended rows (MySQL has no partial unique index):
 *   - current_in_charge_of = jurisdiction id while an in-charge row has no end (unique);
 *   - current_pair = "<jurisdiction>:<employee>" while a row has no end (unique).
 * Both are NULL once the row has an end date, and NULLs never collide.
 *
 * InnoDB (the tables it references are InnoDB; jurisdictions was converted by 2026_10_14_000001). Seeds ONLY the
 * existing in-charges as `in_charge` rows, with effective_from NULL (since records began): no member and no date is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jurisdiction_members')) {
            Schema::create('jurisdiction_members', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('jurisdiction_id');
                $table->string('employee_id', 50);
                $table->string('role', 24); // in_charge | deputy_in_charge | member
                $table->date('effective_from')->nullable(); // NULL = since records began (never an invented date)
                $table->date('effective_to')->nullable();
                $table->string('assigned_by', 50)->nullable();
                $table->string('reason', 500)->nullable();
                $table->boolean('is_correction')->default(false); // a back-dated change (needs jurisdiction.history.correct + reason)
                $table->unsignedBigInteger('current_in_charge_of')->nullable()->unique();
                $table->string('current_pair', 120)->nullable()->unique();
                $table->timestamps();

                $table->index(['jurisdiction_id', 'effective_to']);
                $table->index(['employee_id', 'effective_to']);
                $table->index('role');
            });

            if ($this->canReference('jurisdictions') && $this->canReference('users')) {
                Schema::table('jurisdiction_members', function (Blueprint $table) {
                    $table->foreign('jurisdiction_id')->references('id')->on('jurisdictions')->restrictOnDelete();
                    $table->foreign('employee_id')->references('employee_id')->on('users')->restrictOnDelete();
                    $table->foreign('assigned_by')->references('employee_id')->on('users')->nullOnDelete();
                });
            }
        }

        foreach (DB::table('jurisdictions')->orderBy('id')->get() as $j) {
            if ($j->incharge === null || $j->incharge === '') {
                continue;
            }
            $has = DB::table('jurisdiction_members')->where('jurisdiction_id', $j->id)->where('employee_id', $j->incharge)->whereNull('effective_to')->exists();
            if ($has) {
                continue;
            }
            DB::table('jurisdiction_members')->insert([
                'jurisdiction_id' => $j->id,
                'employee_id' => $j->incharge,
                'role' => 'in_charge',
                'effective_from' => null,
                'effective_to' => null,
                'assigned_by' => null,
                'current_in_charge_of' => $j->id,
                'current_pair' => $j->id.':'.$j->incharge,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdiction_members');
    }

    /** A foreign key needs an InnoDB parent; anywhere else (SQLite tests, an odd production engine) the index alone stays. */
    private function canReference(string $table): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }
        $engine = DB::table('information_schema.tables')->where('table_schema', DB::raw('DATABASE()'))->where('table_name', $table)->value('engine');

        return strtolower((string) $engine) === 'innodb';
    }
};
