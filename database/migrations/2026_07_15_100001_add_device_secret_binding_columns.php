<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_devices', 'device_secret')) {
            Schema::table('user_devices', function (Blueprint $table) {
                // Encrypted at the application layer (Laravel `encrypted` cast),
                // so this holds ciphertext far longer than 255 chars → text.
                $table->text('device_secret')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'device_secret_issued_at')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->timestamp('device_secret_issued_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'current_device_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('current_device_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'current_device_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('current_device_id');
            });
        }

        foreach (['device_secret_issued_at', 'device_secret'] as $column) {
            if (Schema::hasColumn('user_devices', $column)) {
                Schema::table('user_devices', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
