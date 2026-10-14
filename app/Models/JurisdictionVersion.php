<?php

namespace App\Models;

use App\Enums\Carriageway;
use App\Enums\JurisdictionKind;
use App\Services\Corridor\ChainageResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One definition (code, name, boundaries) of a jurisdiction and the dates it was valid (inclusive Asia/Dhaka dates;
 * valid_from NULL = since records began, valid_to NULL = still valid). Never edited except to close it.
 */
class JurisdictionVersion extends Model
{
    protected $fillable = ['jurisdiction_id', 'kind', 'subtype', 'carriageway', 'geometry', 'code', 'name', 'start_m', 'end_m', 'start_chainage', 'end_chainage', 'valid_from', 'valid_to', 'change_reason', 'changed_by'];

    protected $casts = ['kind' => JurisdictionKind::class, 'carriageway' => Carriageway::class, 'geometry' => 'array', 'start_m' => 'integer', 'end_m' => 'integer', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::saving(function (JurisdictionVersion $v): void {
            $v->open_for = $v->valid_to === null ? $v->jurisdiction_id : null;
        });
        static::saved(fn () => app(ChainageResolver::class)->forget());
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class);
    }

    public function coversDate(string $date): bool
    {
        return ($this->valid_from === null || $this->valid_from->toDateString() <= $date)
            && ($this->valid_to === null || $this->valid_to->toDateString() >= $date);
    }
}
