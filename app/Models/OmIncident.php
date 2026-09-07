<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OmIncident extends Model
{
    use HasFactory;

    protected $attributes = ['lock_version' => 0];

    protected $fillable = [
        'incident_number',
        'title',
        'incident_type',
        'detection_source',
        'chainage',
        'latitude',
        'longitude',
        'direction',
        'severity',
        'status',
        'dispatched_unit',
        'response_time_minutes',
        'casualties_fatalities',
        'casualties_injured',
        'vehicles_involved_count',
        'has_asset_damage',
        'asset_damage_cost_est',
        'tppd_claim_status',
        'police_case_number',
        'description',
        'reported_by',
        'escalation_level',
        'escalated_at',
        'escalated_by',
        'escalation_notes',
        'reported_at',
        'dispatched_at',
        'on_scene_at',
        'lane_cleared_at',
        'cleared_at',
        'lock_version',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'on_scene_at' => 'datetime',
        'lane_cleared_at' => 'datetime',
        'cleared_at' => 'datetime',
        'escalated_at' => 'datetime',
        'escalation_level' => 'integer',
        'response_time_minutes' => 'integer',
        'casualties_fatalities' => 'integer',
        'casualties_injured' => 'integer',
        'vehicles_involved_count' => 'integer',
        'has_asset_damage' => 'boolean',
        'asset_damage_cost_est' => 'decimal:2',
        'latitude' => 'float',
        'longitude' => 'float',
        'lock_version' => 'integer',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(OmIncidentVehicle::class, 'incident_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OmIncidentPhoto::class, 'incident_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(OmIncidentEscalation::class, 'incident_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OmActivityLog::class, 'entity_id')
            ->where('entity_type', 'incident');
    }
}
