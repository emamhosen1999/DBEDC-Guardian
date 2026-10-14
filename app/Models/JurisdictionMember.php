<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of an employee holding a role in a jurisdiction's team, effective-dated (inclusive Asia/Dhaka dates, NULL
 * start = since records began, NULL end = open-ended). Rows are closed, never deleted, so the history is whole. Two guard
 * columns (unique, NULL once the row has an end) make the database refuse a second open-ended in-charge and a second
 * open-ended role for the same employee in the same jurisdiction.
 */
class JurisdictionMember extends Model
{
    public const IN_CHARGE = 'in_charge';

    public const DEPUTY = 'deputy_in_charge';

    public const MEMBER = 'member';

    public const ROLES = [self::IN_CHARGE, self::DEPUTY, self::MEMBER];

    /** Tree order: in-charge first, then deputies, then members. */
    public const ROLE_RANK = [self::IN_CHARGE => 0, self::DEPUTY => 1, self::MEMBER => 2];

    protected $fillable = ['jurisdiction_id', 'employee_id', 'role', 'effective_from', 'effective_to', 'assigned_by', 'reason', 'is_correction'];

    protected $casts = ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'is_correction' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (JurisdictionMember $m): void {
            $open = $m->effective_to === null;
            $m->current_in_charge_of = $open && $m->role === self::IN_CHARGE ? $m->jurisdiction_id : null;
            $m->current_pair = $open ? $m->jurisdiction_id.':'.$m->employee_id : null;
        });
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** No end date: the latest row of its line (it may start in the future). */
    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }

    /** True on a date (Y-m-d): inclusive of both ends; a NULL start means since records began. */
    public function coversDate(string $date): bool
    {
        return ($this->effective_from === null || $this->effective_from->toDateString() <= $date)
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $date);
    }
}
