<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_IT_HARDWARE = 'it_hardware';
    public const CATEGORY_SIM_CARD = 'sim_card';
    public const CATEGORY_ACCESS_CARD = 'access_card';
    public const CATEGORY_SAFETY_GEAR = 'safety_gear';
    public const CATEGORY_KEYS = 'keys';
    public const CATEGORY_VEHICLE = 'vehicle';
    public const CATEGORY_OTHER = 'other';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_DISPOSED = 'disposed';

    protected $fillable = [
        'asset_code',
        'name',
        'category',
        'serial_number',
        'assignee_id',
        'assigned_date',
        'return_date',
        'status',
        'condition_on_issue',
        'condition_on_return',
        'notes',
    ];

    protected $casts = [
        'assigned_date' => 'datetime',
        'return_date' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id', 'employee_id')->withTrashed();
    }

    public function isAssigned(): bool
    {
        return $this->status === self::STATUS_ASSIGNED && !empty($this->assignee_id);
    }
}
