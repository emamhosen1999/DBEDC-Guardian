<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hashed, single-use refresh token bound to a (user, device) pair.
 *
 * The plaintext is returned to the client exactly once (at login / rotation) and
 * only its SHA-256 hash is persisted here, so a database read can never recover a
 * usable token. See App\Services\RefreshTokenService for the lifecycle.
 */
class RefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'replaced_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
