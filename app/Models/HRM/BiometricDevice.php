<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BiometricDevice extends Model
{
    protected $fillable = [
        'name',
        'serial_number',
        'model',
        'ip_address',
        'port',
        'location',
        'auth_token',
        'protocol',
        'is_active',
        'last_heartbeat_at',
        'notes',
        'config',
        'users_count',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'config'            => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device) {
            if (empty($device->auth_token)) {
                $device->auth_token = Str::random(48);
            }
        });
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
     * Check if device is online based on last heartbeat.
     * ADMS default polling interval is 30–120 s, so 1 min caused false "offline" flicker.
     * 5 minutes gives enough headroom without masking a genuinely disconnected device.
     */
    public function isOnline(): bool
    {
        if (! $this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->gt(now()->subMinutes(5));
    }

    /**
     * Get online status as a string for display
     */
    public function getOnlineStatusAttribute(): string
    {
        return $this->isOnline() ? 'online' : 'offline';
    }

    public function attendanceTypes()
    {
        return $this->belongsToMany(
            AttendanceType::class,
            'attendance_type_biometric_device',
            'biometric_device_id',
            'attendance_type_id'
        )->withPivot('created_at');
    }
}
