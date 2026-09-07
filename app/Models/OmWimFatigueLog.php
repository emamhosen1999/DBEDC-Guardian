<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OmWimFatigueLog extends Model
{
    use HasFactory;

    protected $table = 'om_wim_fatigue_logs';

    protected $fillable = [
        'log_date',
        'toll_plaza',
        'lane_number',
        'axle_class',
        'gross_weight_tonnes',
        'statutory_weight_limit',
        'overload_percentage',
        'fourth_power_damage_factor',
        'esal_equivalent',
        'estimated_damage_cost_bdt',
        'intercepted_by_patrol',
    ];

    protected $casts = [
        'log_date' => 'date',
        'gross_weight_tonnes' => 'decimal:2',
        'statutory_weight_limit' => 'decimal:2',
        'overload_percentage' => 'decimal:2',
        'fourth_power_damage_factor' => 'decimal:2',
        'esal_equivalent' => 'decimal:2',
        'estimated_damage_cost_bdt' => 'decimal:2',
        'intercepted_by_patrol' => 'boolean',
    ];
}
