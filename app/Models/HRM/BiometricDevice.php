<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class BiometricDevice extends Model
{
    protected $fillable = [
        'name',
        'serial_number',
        'ip_address',
        'location',
        'model',
        'protocol',
        'auth_token',
        'is_active',
        'config',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'config'             => 'array',
        'is_active'          => 'boolean',
        'last_heartbeat_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->auth_token)) {
                $device->auth_token = Str::random(48);
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'biometric_device_users', 'biometric_device_id', 'user_id')
            ->withPivot('device_user_id', 'is_active')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function regenerateToken(): string
    {
        $token = Str::random(48);
        $this->update(['auth_token' => $token]);
        return $token;
    }

    /**
     * Check if device is online based on last heartbeat
     * Device is considered online if heartbeat was within last 1 minute
     */
    public function isOnline(): bool
    {
        if (! $this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->gt(now()->subMinute());
    }

    /**
     * Get online status as a string for display
     */
    public function getOnlineStatusAttribute(): string
    {
        return $this->isOnline() ? 'online' : 'offline';
    }
}
