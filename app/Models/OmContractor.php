<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OmContractor extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'trade_specialty',
        'contact_person',
        'contact_phone',
        'contact_email',
        'contract_reference',
        'contract_start_date',
        'contract_end_date',
        'quality_score',
        'sla_compliance_rate',
        'avg_response_hours',
        'jobs_completed_count',
        'jobs_delayed_count',
        'status',
        'notes',
    ];

    protected $casts = [
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'quality_score' => 'decimal:2',
        'sla_compliance_rate' => 'decimal:2',
        'avg_response_hours' => 'decimal:2',
        'jobs_completed_count' => 'integer',
        'jobs_delayed_count' => 'integer',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(OmWorkOrder::class, 'contractor_name', 'company_name');
    }
}
