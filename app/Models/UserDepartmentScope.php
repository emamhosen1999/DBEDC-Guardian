<?php

namespace App\Models;

use App\Models\HRM\Department;
use App\Services\Access\DepartmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit grant letting a user administer a department they do not head.
 *
 * `admin` = standing grant; `acting` = temporary charge (head on leave etc.).
 * Whether a grant applies is decided at QUERY time by the active() scope, so an
 * expired or not-yet-started grant never needs a cron to stop (or start) applying.
 *
 * @see DepartmentScope
 */
class UserDepartmentScope extends Model
{
    public const TYPE_ADMIN = 'admin';

    public const TYPE_ACTING = 'acting';

    public const TYPES = [self::TYPE_ADMIN, self::TYPE_ACTING];

    protected $fillable = [
        'user_id',
        'department_id',
        'scope_type',
        'starts_at',
        'expires_at',
        'granted_by',
        'reason',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by', 'employee_id')->withTrashed();
    }

    /**
     * Grants in force right now: started (or open-start) and not yet expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    public function isActive(): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->expires_at === null || $this->expires_at->gt($now));
    }

    public function status(): string
    {
        if ($this->expires_at !== null && $this->expires_at->lte(now())) {
            return 'expired';
        }

        if ($this->starts_at !== null && $this->starts_at->gt(now())) {
            return 'scheduled';
        }

        return 'active';
    }
}
