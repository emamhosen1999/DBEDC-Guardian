<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OmPreventiveSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_code',
        'title',
        'description',
        'asset_category',
        'asset_id',
        'frequency_type',
        'frequency_interval_days',
        'priority',
        'assigned_to',
        'contractor_name',
        'estimated_cost',
        'estimated_duration_hours',
        'chainage_from',
        'chainage_to',
        'direction',
        'requires_lane_closure',
        'checklist_items',
        'required_materials',
        'last_generated_at',
        'next_due_at',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'estimated_duration_hours' => 'decimal:2',
        'frequency_interval_days' => 'integer',
        'requires_lane_closure' => 'boolean',
        'checklist_items' => 'array',
        'required_materials' => 'array',
        'last_generated_at' => 'date',
        'next_due_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OmAsset::class, 'asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(OmWorkOrder::class, 'preventive_schedule_id');
    }

    /**
     * Check if this schedule is overdue for WO generation.
     */
    public function isOverdue(): bool
    {
        if (! $this->next_due_at) {
            return true;
        }

        return $this->next_due_at->lte(now()->startOfDay());
    }

    /**
     * Calculate next due date based on frequency.
     */
    public function calculateNextDueDate(): \Carbon\Carbon
    {
        $from = $this->last_generated_at ?? now();

        return match ($this->frequency_type) {
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            'biweekly' => $from->addWeeks(2),
            'monthly' => $from->addMonth(),
            'quarterly' => $from->addMonths(3),
            'semi_annual' => $from->addMonths(6),
            'annual' => $from->addYear(),
            default => $from->addDays($this->frequency_interval_days),
        };
    }
}
