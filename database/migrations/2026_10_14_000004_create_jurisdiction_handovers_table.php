<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An in-charge handover is a first-class event. The row is IMMUTABLE once written (the model refuses updates and
 * deletes): effective date, outgoing and incoming in-charge, note, and a snapshot of the jurisdiction's OPEN daily works at
 * that moment (count plus ids). Moving open works to the incoming in-charge is a separate, explicit, audited action; each
 * run is a row of jurisdiction_handover_transfers, so the handover itself never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jurisdiction_handovers')) {
            Schema::create('jurisdiction_handovers', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('jurisdiction_id')->index();
                $table->date('effective_date')->index();
                $table->string('outgoing_employee_id', 50)->nullable();
                $table->string('incoming_employee_id', 50);
                $table->text('note')->nullable();
                $table->unsignedInteger('open_works_count')->default(0);
                $table->json('open_work_ids');
                $table->timestamp('snapshot_at');
                $table->string('created_by', 50)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('jurisdiction_handover_transfers')) {
            Schema::create('jurisdiction_handover_transfers', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('handover_id')->index();
                $table->string('to_employee_id', 50);
                $table->unsignedInteger('works_count');
                $table->json('work_ids');
                $table->json('previous_incharge'); // work id => the in-charge it had, so the move can be reviewed
                $table->string('performed_by', 50)->nullable();
                $table->timestamp('performed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdiction_handover_transfers');
        Schema::dropIfExists('jurisdiction_handovers');
    }
};
