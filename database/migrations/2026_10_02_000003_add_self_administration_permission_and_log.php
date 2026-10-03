<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Governed segregation-of-duties exception: `access.self-administration`. A documented per-person exception
 * (ISO 27001 A.5.3), never part of a role: each grant names the person and the reason through
 * `php artisan access:self-administration grant`. Compensating control: every self-administration action
 * writes an immutable `self_administration_logs` row and notifies the global admins.
 */
return new class extends Migration
{
    private const PERMISSION = 'access.self-administration';

    public function up(): void
    {
        if (! Schema::hasTable('self_administration_logs')) {
            Schema::create('self_administration_logs', function (Blueprint $table) {
                $table->id();
                $table->string('actor_id', 50);
                $table->string('action', 100);
                $table->string('subject_type', 100)->nullable();
                $table->string('subject_id', 100)->nullable();
                $table->json('changes')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('actor_id')->references('employee_id')->on('users')->restrictOnDelete();
                $table->index(['actor_id', 'created_at']);
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['module' => 'admin', 'description' => 'Self-administration: act on oneself like on department employees (audited, global admins notified)'],
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('self_administration_logs');
    }
};
