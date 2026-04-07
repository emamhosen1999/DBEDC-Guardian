<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('user_devices', 'device_model')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('device_model')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'device_manufacturer')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('device_manufacturer')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'device_brand')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('device_brand')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'os_version')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('os_version')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'app_version')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('app_version')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'build_version')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('build_version')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'hardware_id')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('hardware_id')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'mac_address')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('mac_address')->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'signature_hash')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->string('signature_hash', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('user_devices', 'signature_payload')) {
            Schema::table('user_devices', function (Blueprint $table) {
                $table->json('signature_payload')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'signature_payload',
            'signature_hash',
            'mac_address',
            'hardware_id',
            'build_version',
            'app_version',
            'os_version',
            'device_brand',
            'device_manufacturer',
            'device_model',
        ] as $column) {
            if (Schema::hasColumn('user_devices', $column)) {
                Schema::table('user_devices', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
