<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalSettlement extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'offboarding_id',
        'employee_id',
        'last_working_date',
        'monthly_gross_salary',
        'daily_rate',
        'payable_working_days',
        'earned_salary',
        'unavailed_leave_days',
        'leave_encashment_amount',
        'gratuity_amount',
        'other_earnings',
        'total_earnings',
        'notice_shortfall_days',
        'notice_shortfall_deduction',
        'loan_recovery_amount',
        'asset_damage_deduction',
        'other_deductions',
        'total_deductions',
        'net_payable',
        'status',
        'payment_method',
        'payment_reference',
        'paid_at',
        'remarks',
        'prepared_by',
        'approved_by',
    ];

    protected $casts = [
        'last_working_date' => 'date',
        'monthly_gross_salary' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'payable_working_days' => 'decimal:2',
        'earned_salary' => 'decimal:2',
        'unavailed_leave_days' => 'decimal:2',
        'leave_encashment_amount' => 'decimal:2',
        'gratuity_amount' => 'decimal:2',
        'other_earnings' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'notice_shortfall_days' => 'integer',
        'notice_shortfall_deduction' => 'decimal:2',
        'loan_recovery_amount' => 'decimal:2',
        'asset_damage_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_payable' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function offboarding(): BelongsTo
    {
        return $this->belongsTo(Offboarding::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id', 'employee_id')->withTrashed();
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by', 'employee_id')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'employee_id')->withTrashed();
    }
}
