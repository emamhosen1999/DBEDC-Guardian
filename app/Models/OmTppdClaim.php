<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmTppdClaim extends Model
{
    use HasFactory;

    protected $table = 'om_tppd_claims';

    protected $fillable = [
        'claim_number',
        'incident_id',
        'incident_date',
        'chainage',
        'direction',
        'vehicle_registration_number',
        'driver_name',
        'driver_license_number',
        'insurance_company',
        'insurance_policy_number',
        'police_station',
        'police_fir_number',
        'damaged_components',
        'estimated_repair_cost',
        'actual_repair_cost',
        'claimed_amount',
        'recovered_amount',
        'status',
        'recovery_date',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'recovery_date' => 'date',
        'damaged_components' => 'array',
        'estimated_repair_cost' => 'decimal:2',
        'actual_repair_cost' => 'decimal:2',
        'claimed_amount' => 'decimal:2',
        'recovered_amount' => 'decimal:2',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(OmIncident::class, 'incident_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
