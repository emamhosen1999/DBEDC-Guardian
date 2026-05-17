<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricAttLog extends Model
{
    protected $table = 'biometric_att_logs';

    protected $fillable = [
        'biometric_device_id',
        'serial_number',
        'user_pin',
        'user_id',
        'punch_time',
        'check_type',
        'punch_status',
        'punch_status_reason',
        'verify_code',
        'work_code',
        'raw_data',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'punch_time'  => 'datetime',
        'occurred_at' => 'datetime',
        'context'     => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }
}
