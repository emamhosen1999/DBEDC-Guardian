<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who reported to whom, and when. `users.report_to` is overwritten in place, so until now nothing remembered past reporting
 * lines. Every row here is a period of one line (inclusive Asia/Dhaka dates; valid_from NULL = since records began, valid_to
 * NULL = still valid). `open_for` (unique, NULL once closed) lets the database refuse a second open line per employee.
 *
 * Seeds the CURRENT lines with valid_from NULL: no past date is invented. The seed rows' created_at is the day recording
 * began, which the UI quotes ("Reporting lines are recorded from ...") for earlier dates. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_reporting_lines')) {
            Schema::create('employee_reporting_lines', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('employee_id', 50);
                $table->string('reports_to', 50);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->string('changed_by', 50)->nullable();
                $table->string('open_for', 50)->nullable()->unique();
                $table->timestamps();

                $table->index(['employee_id', 'valid_to']);
                $table->index(['reports_to', 'valid_to']);
            });
        }

        foreach (DB::table('users')->whereNotNull('report_to')->where('report_to', '<>', '')->whereNull('deleted_at')->orderBy('employee_id')->get(['employee_id', 'report_to']) as $u) {
            if (DB::table('employee_reporting_lines')->where('employee_id', $u->employee_id)->exists()) {
                continue;
            }
            DB::table('employee_reporting_lines')->insert([
                'employee_id' => $u->employee_id, 'reports_to' => $u->report_to, 'valid_from' => null, 'valid_to' => null,
                'changed_by' => null, 'open_for' => $u->employee_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_reporting_lines');
    }
};
