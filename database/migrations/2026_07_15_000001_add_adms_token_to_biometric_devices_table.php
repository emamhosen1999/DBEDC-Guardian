<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add an OPTIONAL per-device shared secret for the ZKTeco ADMS (/iclock/*)
     * push endpoints.
     *
     * This is intentionally separate from the existing `auth_token` column
     * (which is auto-generated, unique and NOT NULL, and is used by the JSON
     * /api/biometric/webhook channel). `adms_token` is nullable: NULL means
     * "no ADMS secret configured" and the device is trusted on the
     * registered+active allowlist alone, preserving today's behaviour for live
     * hardware that cannot yet present a token.
     */
    public function up(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->string('adms_token', 64)->nullable()->after('auth_token');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn('adms_token');
        });
    }
};
