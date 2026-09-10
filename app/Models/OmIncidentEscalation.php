<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmIncidentEscalation extends Model
{
    use HasFactory;

    protected $table = 'om_incident_escalations';

    protected $fillable = [
        'incident_id',
        'level',
        'escalated_by',
        'notes',
        'escalated_at',
    ];

    protected $casts = [
        'level' => 'integer',
        'escalated_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(OmIncident::class, 'incident_id');
    }

    public function escalatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }
}
