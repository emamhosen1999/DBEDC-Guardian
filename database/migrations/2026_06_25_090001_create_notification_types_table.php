<?php
// database/migrations/2026_06_25_090001_create_notification_types_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();            // e.g. leave.requested
            $table->string('category');                 // e.g. leave, attendance
            $table->string('label');
            $table->string('description')->nullable();
            $table->json('default_channels');           // ["database","push","mail"]
            $table->json('locked_channels')->nullable();// channels users cannot disable
            $table->json('recipient_roles')->nullable();// ["Employee"] (informational/targeting hint)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_types');
    }
};
