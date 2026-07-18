<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-controlled feature flags / remote config.
 *
 * The mobile app is a native binary: changing behaviour normally costs a store
 * release. These rows are read at runtime by GET /api/v1/config, so a flag can
 * be flipped from the admin UI and take effect on the next foreground.
 *
 * Scope is deliberately NOT a rules engine: a row is either GLOBAL (role null)
 * or pinned to ONE role name. Resolution = role-scoped row wins over global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191);
            // Null = pure on/off flag. Non-null = remote config payload (JSON:
            // scalar, array or object) delivered to the client alongside the
            // enabled state.
            $table->json('value')->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            // Null = applies to everyone. Otherwise a Spatie role NAME; that row
            // overrides the global row for users holding the role.
            $table->string('role', 191)->nullable();
            // MILLISECOND precision on purpose: the resolver's cache key is
            // derived from MAX(updated_at), so second-granularity timestamps
            // would let two edits inside the same second share a cache key and
            // serve a stale flag for the whole TTL.
            $table->timestamps(3);

            // One row per (key, scope). The global row is the (key, null) pair.
            $table->unique(['key', 'role']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
