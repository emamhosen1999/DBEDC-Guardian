<?php

namespace App\Models;

use App\Enums\Carriageway;
use App\Enums\JurisdictionKind;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionVersionService;
use App\Support\Corridor\Chainage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project phase: a stretch of the expressway (start_m..end_m, metres from K0+000) and the team that runs it.
 *
 * The numbers are the truth. `location` (the name), `start_chainage` and `end_chainage` are the display strings the
 * rest of the system has always read; they are derived from `name` / `start_m` / `end_m` on save, and a caller that
 * still writes only the strings (older code, tests) has the numbers derived from them.
 *
 * HISTORICAL (SCD Type 2): this row is the stable identity and mirrors the CURRENT values. What was true on any date lives in
 * jurisdiction_versions (boundaries, name) and jurisdiction_members (team); `incharge` mirrors the in-charge valid today.
 */
class Jurisdiction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'kind',
        'subtype',
        'carriageway',
        'geometry',
        'code',
        'name',
        'location',
        'start_chainage',
        'end_chainage',
        'start_m',
        'end_m',
        'incharge',
        'description',
        'colour_token',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'kind' => JurisdictionKind::class,
        'carriageway' => Carriageway::class,
        'geometry' => 'array',
        'start_m' => 'integer',
        'end_m' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Jurisdiction $j): void {
            // name <-> location
            if ($j->isDirty('name') && $j->name !== null && $j->name !== '') {
                $j->location = $j->name;
            } elseif ($j->isDirty('location') || $j->name === null) {
                $j->name = $j->location;
            }
            // numbers -> strings (or strings -> numbers for a writer that only knows the strings)
            foreach (['start' => 'start_chainage', 'end' => 'end_chainage'] as $side => $text) {
                $metres = "{$side}_m";
                if ($j->isDirty($metres) && $j->{$metres} !== null) {
                    $j->{$text} = Chainage::format((int) $j->{$metres});
                } elseif ($j->{$text} !== null && $j->{$text} !== '' && ($j->isDirty($text) || $j->{$metres} === null)) {
                    $j->{$metres} = Chainage::token((string) $j->{$text});
                }
                $j->{$text} ??= ''; // an area has no chainage (the legacy column is NOT NULL on some engines)
            }
            $j->kind ??= JurisdictionKind::Section;
        });
        $forget = static fn () => app(ChainageResolver::class)->forget();
        // A direct write (older code, tests) keeps the versions in step; JurisdictionVersionService manages its own.
        static::saved(function (Jurisdiction $j): void {
            if (! JurisdictionVersionService::$managing) {
                app(JurisdictionVersionService::class)->syncFromModel($j);
            }
        });
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }

    /** Sections only: the jurisdictions daily works are attributed to, and the only ones that route in-charge visibility. */
    public function scopeSections($query)
    {
        return $query->where('kind', JurisdictionKind::Section->value);
    }

    /** Retired = its latest definition has ended (it is not deleted: its history stays visible as of past dates). */
    public function isRetired(): bool
    {
        return ! $this->trashed() && ! JurisdictionVersion::query()->where('jurisdiction_id', $this->id)->whereNull('valid_to')->exists()
            && JurisdictionVersion::query()->where('jurisdiction_id', $this->id)->exists();
    }

    /** The in-charge employee (kept for older callers; `incharge` holds an employee id). */
    public function inchargeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incharge');
    }

    public function members(): HasMany
    {
        return $this->hasMany(JurisdictionMember::class);
    }

    public function currentMembers(): HasMany
    {
        return $this->members()->whereNull('effective_to');
    }

    public function currentInCharge(): HasOne
    {
        return $this->hasOne(JurisdictionMember::class)->where('role', JurisdictionMember::IN_CHARGE)->whereNull('effective_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lengthM(): ?int
    {
        return $this->start_m !== null && $this->end_m !== null ? $this->end_m - $this->start_m + 1 : null;
    }
}
