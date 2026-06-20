<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'scope_type', 'scope_id', 'priority', 'effective_from', 'effective_to',
        'version_group_id', 'version', 'status', 'punch_strictness', 'outside_window_minutes',
        'grace_tiers', 'rounding', 'rule_overrides', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
        'scope_id' => 'integer',
        'version_group_id' => 'integer',
        'version' => 'integer',
        'outside_window_minutes' => 'integer',
        'grace_tiers' => 'array',
        'rounding' => 'array',
        'rule_overrides' => 'array',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeForScope(Builder $q, string $type, ?int $id): Builder
    {
        return $q->where('scope_type', $type)->where('scope_id', $id);
    }
}
