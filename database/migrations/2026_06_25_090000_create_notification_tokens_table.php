<?php
// database/migrations/2026_06_25_090000_create_notification_tokens_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16)->default('fcm'); // fcm | expo
            $table->string('token', 512); // FCM ≤152, Expo ≤50, APNs ≤100 — 512 fits all providers
            $table->string('platform', 16)->default('web'); // web | android | ios
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique('token');
            $table->index(['user_id', 'provider']);
        });

        // Backfill existing single-column tokens (column dropped later in Task 14).
        if (Schema::hasColumn('users', 'fcm_token')) {
            DB::table('users')->whereNotNull('fcm_token')->orderBy('id')->each(function ($u) {
                DB::table('notification_tokens')->insertOrIgnore([
                    'user_id' => $u->id,
                    'provider' => 'fcm',
                    'token' => $u->fcm_token,
                    'platform' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_tokens');
    }
};
