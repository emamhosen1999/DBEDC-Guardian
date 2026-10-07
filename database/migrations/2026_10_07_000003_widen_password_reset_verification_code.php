<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The password-reset OTP is stored as a bcrypt hash (60 chars), never in plain text, so the column that held
 * the 6-digit code is widened. Codes already issued are plain text and valid for at most an hour; they are
 * discarded so no plain code survives the change (the user simply requests a new one).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('password_reset_tokens_secure')) {
            return;
        }

        DB::table('password_reset_tokens_secure')->delete();

        Schema::table('password_reset_tokens_secure', function (Blueprint $table) {
            $table->string('verification_code', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('password_reset_tokens_secure')) {
            return;
        }

        DB::table('password_reset_tokens_secure')->delete();

        Schema::table('password_reset_tokens_secure', function (Blueprint $table) {
            $table->string('verification_code', 6)->nullable()->change();
        });
    }
};
