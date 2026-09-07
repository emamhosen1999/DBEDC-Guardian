<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmToolboxTalk extends Model
{
    use HasFactory;

    protected $fillable = [
        'talk_code',
        'talk_date',
        'supervisor_id',
        'topic',
        'work_order_id',
        'chainage',
        'attendees',
        'attendee_count',
        'ppe_verified',
        'traffic_management_briefed',
        'emergency_response_briefed',
        'hazards_identified',
        'photo_paths',
    ];

    protected $casts = [
        'talk_date' => 'date',
        'attendees' => 'array',
        'attendee_count' => 'integer',
        'ppe_verified' => 'boolean',
        'traffic_management_briefed' => 'boolean',
        'emergency_response_briefed' => 'boolean',
        'photo_paths' => 'array',
    ];

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(OmWorkOrder::class, 'work_order_id');
    }
}
