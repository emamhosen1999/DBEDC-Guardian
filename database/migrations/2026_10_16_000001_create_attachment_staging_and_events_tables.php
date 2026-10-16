<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded and scanned documents wait here (private disk) until the application they belong to is submitted; unattached
 * ones expire after 24 h. attachment_events is the audit trail of "request re-upload" / "re-uploaded" actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staged_attachments')) {
            Schema::create('staged_attachments', function (Blueprint $table) {
                $table->id();
                $table->uuid('token')->unique();
                $table->string('user_id', 50)->index();
                $table->string('kind', 16); // upload | scan_page | scan_pdf
                $table->string('path'); // processed file on the private disk
                $table->string('original_path')->nullable(); // untouched scan photo (re-flattened when the corners are adjusted)
                $table->string('mime', 64);
                $table->string('original_name')->nullable(); // metadata only, never used as a path
                $table->unsignedInteger('size');
                $table->unsignedSmallInteger('page_count')->default(1);
                $table->string('mode', 8)->nullable(); // color | grey | bw
                $table->json('corners')->nullable();
                $table->json('preview_paths')->nullable(); // a scanned PDF keeps its page images for the inline viewer (mobile has no PDF viewer)
                $table->decimal('confidence', 4, 3)->nullable();
                $table->json('clarity')->nullable();
                $table->boolean('clarity_passed')->default(false);
                $table->timestamp('attached_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('attachment_events')) {
            Schema::create('attachment_events', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 64);
                $table->unsignedBigInteger('subject_id');
                $table->string('actor_id', 50);
                $table->string('action', 32); // reupload_requested | reuploaded
                $table->text('reason')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_events');
        Schema::dropIfExists('staged_attachments');
    }
};
