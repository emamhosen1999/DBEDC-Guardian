<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmSafetyIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'safety_number',
        'title',
        'incident_type',
        'severity',
        'location',
        'chainage',
        'latitude',
        'longitude',
        'occurred_at',
        'reported_by',
        'reported_at',
        'description',
        'immediate_action_taken',
        'root_cause',
        'corrective_action',
        'persons_involved',
        'photo_paths',
        'ppe_worn',
        'toolbox_talk_done',
        'work_order_ref',
        'status',
        'investigated_by',
        'investigated_at',
        'closed_by',
        'closed_at',
        'lost_time_hours',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'investigated_at' => 'datetime',
        'closed_at' => 'datetime',
        'persons_involved' => 'array',
        'photo_paths' => 'array',
        'ppe_worn' => 'boolean',
        'toolbox_talk_done' => 'boolean',
        'lost_time_hours' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigated_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
