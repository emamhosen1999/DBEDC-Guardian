<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmShiftLog extends Model
{
    use HasFactory;

    protected $attributes = ['lock_version' => 0];

    protected $fillable = [
        'shift_code',
        'shift_date',
        'shift_type',
        'operator_id',
        'incoming_operator_id',
        'open_incidents_count',
        'active_lane_closures_count',
        'weather_condition',
        'cctv_offline_count',
        'vms_offline_count',
        'wim_offline_count',
        'handover_notes',
        'equipment_exceptions',
        'is_acknowledged',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'lock_version',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
        'open_incidents_count' => 'integer',
        'active_lane_closures_count' => 'integer',
        'cctv_offline_count' => 'integer',
        'vms_offline_count' => 'integer',
        'wim_offline_count' => 'integer',
        'lock_version' => 'integer',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function incomingOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incoming_operator_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
