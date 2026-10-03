<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog A1: the access audit ledger. One immutable row per change of who holds which role or
 * permission (written by App\Listeners\RecordAccessAudit from Spatie's Role/Permission events, and by
 * `access:apply-catalog` / the catalog migrations). actor_id and subject_id are plain strings, not
 * foreign keys: the ledger must survive the deletion of the people it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_audit_logs')) {
            return;
        }

        Schema::create('access_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_id', 50)->nullable();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('action', 100);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('plan_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('plan_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_logs');
    }
};
