<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmSlaBreach extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'entity_number',
        'sla_hours',
        'sla_started_at',
        'sla_due_at',
        'breached_at',
        'overdue_hours',
        'escalation_level',
        'acknowledged',
        'acknowledged_by',
        'acknowledged_at',
        'notes',
    ];

    protected $casts = [
        'sla_started_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'breached_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'sla_hours' => 'integer',
        'overdue_hours' => 'integer',
        'acknowledged' => 'boolean',
    ];

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
