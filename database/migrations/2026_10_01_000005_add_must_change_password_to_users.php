<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin-set password is known to the admin: the employee must replace it at first use. The flag is
 * raised when an admin resets someone's password or creates an account with an admin-chosen one, and
 * cleared by the employee's own password change (or a password-reset-by-email).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('must_change_password')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('must_change_password');
            });
        }
    }
};
