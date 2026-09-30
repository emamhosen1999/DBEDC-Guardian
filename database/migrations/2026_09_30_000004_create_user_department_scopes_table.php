<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit department-scope grants: which departments a (non-global) user may
 * administer, beyond the department(s) they head. `admin` is a standing grant,
 * `acting` a temporary charge (e.g. while the head is on leave) that is expected
 * to carry an expires_at. Activity is evaluated at query time, so an expired
 * grant stops applying immediately without a cron.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_department_scopes')) {
            return;
        }

        Schema::create('user_department_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 50);
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('scope_type', 20)->default('admin'); // admin | acting
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('granted_by', 50)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('employee_id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by')->references('employee_id')->on('users')->nullOnDelete();

            $table->unique(['user_id', 'department_id', 'scope_type'], 'user_department_scopes_unique');
            $table->index(['user_id', 'expires_at'], 'user_department_scopes_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_department_scopes');
    }
};
