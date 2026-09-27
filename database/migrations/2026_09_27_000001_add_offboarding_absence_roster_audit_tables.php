<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure offboardings engine is InnoDB so foreign keys can be referenced
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE offboardings ENGINE=InnoDB');
        }

        // Extend offboardings table with new reasons + notice tracking
        Schema::table('offboardings', function (Blueprint $table) {
            if (!Schema::hasColumn('offboardings', 'resignation_received_at')) {
                $table->dateTime('resignation_received_at')->nullable()->after('exit_interview_date');
            }
            if (!Schema::hasColumn('offboardings', 'notice_days_required')) {
                $table->unsignedSmallInteger('notice_days_required')->default(0)->after('resignation_received_at');
            }
            if (!Schema::hasColumn('offboardings', 'notice_shortfall_days')) {
                $table->unsignedSmallInteger('notice_shortfall_days')->default(0)->after('notice_days_required');
            }
        });

        // Ensure offboarding_tasks has soft deletes
        if (Schema::hasTable('offboarding_tasks') && !Schema::hasColumn('offboarding_tasks', 'deleted_at')) {
            Schema::table('offboarding_tasks', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // Absence tracking / no-show escalation
        if (!Schema::hasTable('absence_cases')) {
            Schema::create('absence_cases', function (Blueprint $table) {
                $table->id();
                $table->string('user_id');           // employee_id FK
                $table->date('first_absent_date');
                $table->unsignedSmallInteger('streak_days')->default(1);
                $table->string('stage')->default('monitoring');  // monitoring, notice_sent, show_cause, deemed_resignation, returned, absconded
                $table->unsignedTinyInteger('notices_sent')->default(0);
                $table->string('outcome')->nullable();           // regularized, lwp, absconded, returned
                $table->unsignedBigInteger('offboarding_id')->nullable();
                $table->json('timeline')->nullable();            // [{date, action, note}]
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('employee_id')->on('users')->onDelete('cascade');
                $table->foreign('offboarding_id')->references('id')->on('offboardings')->onDelete('set null');
                $table->unique(['user_id', 'first_absent_date']);
            });
        }

        // Roster audit trail
        if (!Schema::hasTable('roster_day_changes')) {
            Schema::create('roster_day_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('roster_day_id');
                $table->string('actor_id');          // employee_id of the person who made the change
                $table->string('field');              // shift_id, source, locked, etc.
                $table->string('old_value')->nullable();
                $table->string('new_value')->nullable();
                $table->string('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('roster_day_id')->references('id')->on('roster_days')->onDelete('cascade');
                $table->foreign('actor_id')->references('employee_id')->on('users')->onDelete('cascade');
                $table->index('roster_day_id');
            });
        }

        // Add swap_request_id FK on roster_days table for swap tracking
        if (!Schema::hasColumn('roster_days', 'swap_request_id')) {
            Schema::table('roster_days', function (Blueprint $table) {
                $table->unsignedBigInteger('swap_request_id')->nullable()->after('assignment_id');
                $table->foreign('swap_request_id')->references('id')->on('shift_swap_requests')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            $table->dropForeign(['swap_request_id']);
            $table->dropColumn('swap_request_id');
        });

        Schema::dropIfExists('roster_day_changes');
        Schema::dropIfExists('absence_cases');

        Schema::table('offboardings', function (Blueprint $table) {
            $table->dropColumn(['resignation_received_at', 'notice_days_required', 'notice_shortfall_days']);
        });
    }
};
