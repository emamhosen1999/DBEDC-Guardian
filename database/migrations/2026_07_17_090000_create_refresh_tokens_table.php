<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh-token rotation store (ADDITIVE).
 *
 * A refresh token is a long-lived, single-use credential minted alongside the
 * short(er)-lived Sanctum access token at login. It is stored ONLY as a SHA-256
 * hash (never plaintext), bound to the (user, device) pair, and rotated on every
 * use so a stolen token is invalidated the moment the legitimate client next
 * refreshes. `replaced_by` links a token to its successor to make a rotation
 * chain auditable and to power reuse (theft) detection.
 *
 * Existing access-token / session behaviour is untouched — this table is new and
 * consumed only by the opt-in POST /api/v1/auth/refresh flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // UUIDv4 device identifier the token is bound to (nullable so a
            // client that never sends one is still supported).
            $table->string('device_id', 36)->nullable();
            // SHA-256 hex digest of the plaintext refresh token (64 chars).
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Successor token id after a rotation (chain link for reuse detection).
            $table->unsignedBigInteger('replaced_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'device_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
