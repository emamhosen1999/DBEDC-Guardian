<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'symbol',
        'days',
        'eligibility',
        'carry_forward',
        'earned_leave',
        'is_earned',
        'is_paid',
        'requires_approval',
        'auto_approve',
        'special_conditions',
        // Phase 3 — configurable accrual policy
        'accrual_method',
        'accrual_rate',
        'probation_months',
        'prorate_on_join',
        'carry_forward_cap',
        'carry_expiry_months',
        'is_encashable',
        'allow_negative',
    ];

    protected $casts = [
        'id' => 'integer',
        'type' => 'string',
        'days' => 'integer',
        'eligibility' => 'string',
        'carry_forward' => 'boolean',
        'earned_leave' => 'boolean',
        'is_earned' => 'boolean',
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'auto_approve' => 'boolean',
        'special_conditions' => 'string',
        // Phase 3
        'accrual_method' => 'string',
        'accrual_rate' => 'decimal:2',
        'probation_months' => 'integer',
        'prorate_on_join' => 'boolean',
        'carry_forward_cap' => 'decimal:1',
        'carry_expiry_months' => 'integer',
        'is_encashable' => 'boolean',
        'allow_negative' => 'boolean',
    ];

    public function leaves()
    {
        return $this->hasMany(Leave::class, 'leave_type');
    }
}
