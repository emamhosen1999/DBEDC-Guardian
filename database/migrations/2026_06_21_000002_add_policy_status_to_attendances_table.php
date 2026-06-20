<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->enum('policy_status', ['accepted', 'provisional', 'rejected'])->default('accepted')->after('symbol');
            $table->boolean('needs_approval')->default(false)->after('policy_status');
            $table->string('policy_exception_reason')->nullable()->after('needs_approval');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['policy_status', 'needs_approval', 'policy_exception_reason']);
        });
    }
};
