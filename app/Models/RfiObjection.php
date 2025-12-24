<?php

namespace App\Models;

use App\Traits\ChainageMatcher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RfiObjection extends Model implements HasMedia
{
    use ChainageMatcher, HasFactory, InteractsWithMedia, SoftDeletes;

    /**
     * Status constants for objection workflow
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Category constants for objection types
     */
    public const CATEGORY_DESIGN_CONFLICT = 'design_conflict';

    public const CATEGORY_SITE_MISMATCH = 'site_mismatch';

    public const CATEGORY_MATERIAL_CHANGE = 'material_change';

    public const CATEGORY_SAFETY_CONCERN = 'safety_concern';

    public const CATEGORY_SPECIFICATION_ERROR = 'specification_error';

    public const CATEGORY_OTHER = 'other';

    /**
     * Valid statuses for validation
     *
     * @var array<string>
     */
    public static array $statuses = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_REJECTED,
    ];

    /**
     * Statuses considered "active" (blocking)
     *
     * @var array<string>
     */
    public static array $activeStatuses = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
    ];

    /**
     * Valid categories for validation
     *
     * @var array<string>
     */
    public static array $categories = [
        self::CATEGORY_DESIGN_CONFLICT,
        self::CATEGORY_SITE_MISMATCH,
        self::CATEGORY_MATERIAL_CHANGE,
        self::CATEGORY_SAFETY_CONCERN,
        self::CATEGORY_SPECIFICATION_ERROR,
        self::CATEGORY_OTHER,
    ];

    /**
     * Human-readable category labels
     *
     * @var array<string, string>
     */
    public static array $categoryLabels = [
        self::CATEGORY_DESIGN_CONFLICT => 'Design Conflict',
        self::CATEGORY_SITE_MISMATCH => 'Site Condition Mismatch',
        self::CATEGORY_MATERIAL_CHANGE => 'Material Change',
        self::CATEGORY_SAFETY_CONCERN => 'Safety Concern',
        self::CATEGORY_SPECIFICATION_ERROR => 'Specification Error',
        self::CATEGORY_OTHER => 'Other',
    ];

    /**
     * Human-readable status labels
     *
     * @var array<string, string>
     */
    public static array $statusLabels = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_UNDER_REVIEW => 'Under Review',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'title',
        'category',
        'chainage_from',
        'chainage_to',
        'description',
        'reason',
        'status',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'created_by',
        'updated_by',
        'was_overridden',
        'override_reason',
        'overridden_by',
        'overridden_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'overridden_at' => 'datetime',
        'was_overridden' => 'boolean',
    ];

    /**
     * Attributes to append to JSON serialization
     */
    protected $appends = ['files_count', 'is_active', 'category_label', 'status_label', 'affected_rfis_count'];

    /**
     * Register media collections for objection files.
     * Supports multiple files (images and documents) per objection.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('objection_files')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.dwg',
                'application/dxf',
            ])
            ->useDisk('public');
    }

    /**
     * Register media conversions for thumbnails.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10)
            ->nonQueued()
            ->performOnCollections('objection_files');
    }

    // ==================== Relationships ====================

    /**
     * Get the daily works (RFIs) this objection is attached to (many-to-many).
     */
    public function dailyWorks(): BelongsToMany
    {
        return $this->belongsToMany(DailyWork::class, 'daily_work_objection')
            ->withPivot(['attached_by', 'attached_at', 'attachment_notes'])
            ->withTimestamps();
    }

    /**
     * Get the user who created this objection.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this objection.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user who resolved this objection.
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the user who overrode this objection.
     */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * Get the status change logs for this objection.
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(RfiObjectionStatusLog::class)->orderBy('changed_at', 'desc');
    }

    /**
     * Get all chainages for this objection.
     */
    public function chainages(): HasMany
    {
        return $this->hasMany(ObjectionChainage::class, 'objection_id');
    }

    /**
     * Get specific chainages only.
     */
    public function specificChainages(): HasMany
    {
        return $this->hasMany(ObjectionChainage::class, 'objection_id')
            ->where('entry_type', ObjectionChainage::TYPE_SPECIFIC);
    }

    /**
     * Get range chainages only.
     */
    public function rangeChainages(): HasMany
    {
        return $this->hasMany(ObjectionChainage::class, 'objection_id')
            ->whereIn('entry_type', [ObjectionChainage::TYPE_RANGE_START, ObjectionChainage::TYPE_RANGE_END]);
    }

    // ==================== Accessors ====================

    /**
     * Get files count attribute.
     */
    public function getFilesCountAttribute(): int
    {
        return $this->getMedia('objection_files')->count();
    }

    /**
     * Get files with formatted data.
     */
    public function getFilesAttribute(): array
    {
        return $this->getMedia('objection_files')->map(function ($media) {
            return [
                'id' => $media->id,
                'name' => $media->file_name,
                'url' => $media->getUrl(),
                'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'human_size' => $this->formatBytes($media->size),
                'is_image' => str_starts_with($media->mime_type, 'image/'),
                'is_pdf' => $media->mime_type === 'application/pdf',
                'created_at' => $media->created_at->toISOString(),
            ];
        })->toArray();
    }

    /**
     * Check if objection is in an active (blocking) state.
     */
    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, self::$activeStatuses, true);
    }

    /**
     * Get human-readable category label.
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::$categoryLabels[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category ?? 'Unknown'));
    }

    /**
     * Get human-readable status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::$statusLabels[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    // ==================== Scopes ====================

    /**
     * Scope to only active (blocking) objections.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', self::$activeStatuses);
    }

    /**
     * Scope to only resolved/closed objections.
     */
    public function scopeResolved($query)
    {
        return $query->whereIn('status', [self::STATUS_RESOLVED, self::STATUS_REJECTED]);
    }

    /**
     * Scope by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope by daily work.
     */
    public function scopeForDailyWork($query, int $dailyWorkId)
    {
        return $query->where('daily_work_id', $dailyWorkId);
    }

    // ==================== Methods ====================

    /**
     * Transition objection to a new status with logging.
     */
    public function transitionTo(string $newStatus, ?string $notes = null, ?int $changedBy = null): bool
    {
        if (! in_array($newStatus, self::$statuses, true)) {
            throw new \InvalidArgumentException("Invalid status: {$newStatus}");
        }

        $oldStatus = $this->status;

        // Create status log entry
        RfiObjectionStatusLog::create([
            'rfi_objection_id' => $this->id,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'notes' => $notes,
            'changed_by' => $changedBy ?? auth()->id(),
            'changed_at' => now(),
        ]);

        // Update status
        $this->status = $newStatus;

        // Set resolution fields if resolving
        if (in_array($newStatus, [self::STATUS_RESOLVED, self::STATUS_REJECTED])) {
            $this->resolved_by = $changedBy ?? auth()->id();
            $this->resolved_at = now();
            if ($notes) {
                $this->resolution_notes = $notes;
            }
        }

        return $this->save();
    }

    /**
     * Submit the objection for review.
     */
    public function submit(?string $notes = null): bool
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only draft objections can be submitted.');
        }

        return $this->transitionTo(self::STATUS_SUBMITTED, $notes);
    }

    /**
     * Move objection to under review.
     */
    public function startReview(?string $notes = null): bool
    {
        if ($this->status !== self::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException('Only submitted objections can be reviewed.');
        }

        return $this->transitionTo(self::STATUS_UNDER_REVIEW, $notes);
    }

    /**
     * Resolve the objection.
     */
    public function resolve(string $resolutionNotes): bool
    {
        if (! in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW])) {
            throw new \InvalidArgumentException('Only submitted or under-review objections can be resolved.');
        }

        return $this->transitionTo(self::STATUS_RESOLVED, $resolutionNotes);
    }

    /**
     * Reject the objection.
     */
    public function reject(string $rejectionReason): bool
    {
        if (! in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW])) {
            throw new \InvalidArgumentException('Only submitted or under-review objections can be rejected.');
        }

        return $this->transitionTo(self::STATUS_REJECTED, $rejectionReason);
    }

    // ==================== Chainage Management Methods ====================

    /**
     * Sync all chainages for this objection.
     * Replaces all existing chainages with the provided data.
     *
     * @param  array<string>  $specificChainages  Array of specific chainage strings
     * @param  string|null  $rangeFrom  Range start chainage
     * @param  string|null  $rangeTo  Range end chainage
     * @return array Summary of created entries
     */
    public function syncChainages(array $specificChainages = [], ?string $rangeFrom = null, ?string $rangeTo = null): array
    {
        // Delete existing chainages
        $this->chainages()->delete();

        $created = [
            'specific' => [],
            'range' => null,
        ];

        // Create specific chainages
        foreach ($specificChainages as $chainage) {
            $chainage = trim($chainage);
            if (empty($chainage)) {
                continue;
            }

            $entry = ObjectionChainage::createFromString($this->id, $chainage, ObjectionChainage::TYPE_SPECIFIC);
            if ($entry) {
                $created['specific'][] = $entry;
            }
        }

        // Create range if both endpoints provided
        if (! empty($rangeFrom) && ! empty($rangeTo)) {
            $created['range'] = ObjectionChainage::createRange($this->id, $rangeFrom, $rangeTo);
        }

        return $created;
    }

    /**
     * Add specific chainages from a comma-separated string.
     *
     * @param  string  $chainages  Comma-separated chainages (e.g., "K35+897, K36+987")
     * @return array<ObjectionChainage> Created entries
     */
    public function addSpecificChainages(string $chainages): array
    {
        return ObjectionChainage::createMultipleSpecific($this->id, $chainages);
    }

    /**
     * Set range chainages for this objection.
     *
     * @param  string  $from  Range start
     * @param  string  $to  Range end
     * @return array{start: ObjectionChainage|null, end: ObjectionChainage|null}
     */
    public function setRangeChainages(string $from, string $to): array
    {
        // Remove existing range entries
        $this->chainages()->whereIn('entry_type', [
            ObjectionChainage::TYPE_RANGE_START,
            ObjectionChainage::TYPE_RANGE_END,
        ])->delete();

        return ObjectionChainage::createRange($this->id, $from, $to);
    }

    /**
     * Get all specific chainage meters for matching.
     *
     * @return array<int>
     */
    public function getSpecificChainageMeters(): array
    {
        return $this->chainages()
            ->where('entry_type', ObjectionChainage::TYPE_SPECIFIC)
            ->pluck('chainage_meters')
            ->toArray();
    }

    /**
     * Get range start and end meters.
     *
     * @return array{start: int|null, end: int|null}
     */
    public function getRangeMeters(): array
    {
        $rangeEntries = $this->chainages()
            ->whereIn('entry_type', [ObjectionChainage::TYPE_RANGE_START, ObjectionChainage::TYPE_RANGE_END])
            ->get();

        return [
            'start' => $rangeEntries->firstWhere('entry_type', ObjectionChainage::TYPE_RANGE_START)?->chainage_meters,
            'end' => $rangeEntries->firstWhere('entry_type', ObjectionChainage::TYPE_RANGE_END)?->chainage_meters,
        ];
    }

    /**
     * Check if this objection's chainages match a given RFI location.
     *
     * @param  string|null  $rfiLocation  The RFI's location string
     * @return bool True if there's any chainage match
     */
    public function matchesRfiLocation(?string $rfiLocation): bool
    {
        if (empty($rfiLocation)) {
            return false;
        }

        $specificMeters = $this->getSpecificChainageMeters();
        $range = $this->getRangeMeters();

        return $this->doesObjectionMatchRfi(
            $specificMeters,
            $range['start'],
            $range['end'],
            $rfiLocation
        );
    }

    /**
     * Get a formatted summary of chainages for display.
     *
     * @return array{specific: array<string>, range: string|null}
     */
    public function getChainageSummary(): array
    {
        $specific = $this->chainages()
            ->where('entry_type', ObjectionChainage::TYPE_SPECIFIC)
            ->pluck('chainage')
            ->toArray();

        $rangeStart = $this->chainages()
            ->where('entry_type', ObjectionChainage::TYPE_RANGE_START)
            ->first();
        $rangeEnd = $this->chainages()
            ->where('entry_type', ObjectionChainage::TYPE_RANGE_END)
            ->first();

        $range = null;
        if ($rangeStart && $rangeEnd) {
            $range = $rangeStart->chainage.' - '.$rangeEnd->chainage;
        }

        return [
            'specific' => $specific,
            'range' => $range,
        ];
    }

    /**
     * Check if status is valid.
     */
    public static function isValidStatus(?string $status): bool
    {
        if ($status === null) {
            return true;
        }

        return in_array($status, self::$statuses, true);
    }

    /**
     * Check if category is valid.
     */
    public static function isValidCategory(?string $category): bool
    {
        if ($category === null) {
            return true;
        }

        return in_array($category, self::$categories, true);
    }

    /**
     * Format bytes to human readable size.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }

    /**
     * Boot method with validation.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($objection) {
            // Validate status
            if ($objection->status && ! self::isValidStatus($objection->status)) {
                throw new \InvalidArgumentException(
                    "Invalid status '{$objection->status}'. Valid statuses are: ".implode(', ', self::$statuses)
                );
            }

            // Validate category
            if ($objection->category && ! self::isValidCategory($objection->category)) {
                throw new \InvalidArgumentException(
                    "Invalid category '{$objection->category}'. Valid categories are: ".implode(', ', self::$categories)
                );
            }

            // Set updated_by if authenticated
            if (auth()->check()) {
                $objection->updated_by = auth()->id();
            }
        });

        static::creating(function ($objection) {
            // Set created_by if not already set
            if (! $objection->created_by && auth()->check()) {
                $objection->created_by = auth()->id();
            }

            // Default status to draft
            if (! $objection->status) {
                $objection->status = self::STATUS_DRAFT;
            }
        });
    }

    // ==================== Many-to-Many Helper Methods ====================

    /**
     * Attach this objection to multiple RFIs.
     *
     * @param  array<int>  $rfiIds
     */
    public function attachToRfis(array $rfiIds, ?string $notes = null): void
    {
        $attachData = [];
        foreach ($rfiIds as $rfiId) {
            $attachData[$rfiId] = [
                'attached_by' => auth()->id(),
                'attached_at' => now(),
                'attachment_notes' => $notes,
            ];
        }

        $this->dailyWorks()->syncWithoutDetaching($attachData);
    }

    /**
     * Detach this objection from specified RFIs.
     *
     * @param  array<int>  $rfiIds
     * @return int Number of RFIs detached
     */
    public function detachFromRfis(array $rfiIds): int
    {
        return $this->dailyWorks()->detach($rfiIds);
    }

    /**
     * Get count of affected RFIs.
     */
    public function getAffectedRfisCountAttribute(): int
    {
        return $this->dailyWorks()->count();
    }

    /**
     * Suggest RFIs based on chainage matching.
     * Returns RFIs whose location matches this objection's chainages.
     *
     * @param  int  $limit  Maximum number of RFIs to return
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function suggestAffectedRfis(int $limit = 100)
    {
        $specificMeters = $this->getSpecificChainageMeters();
        $range = $this->getRangeMeters();

        // If no chainages defined, return empty collection
        if (empty($specificMeters) && ($range['start'] === null || $range['end'] === null)) {
            // Fall back to legacy chainage_from/chainage_to if no new chainages
            if (! empty($this->chainage_from)) {
                $specificMeters = $this->parseMultipleChainages($this->chainage_from);
            }
            if (! empty($this->chainage_from) && ! empty($this->chainage_to)) {
                $range['start'] = $this->parseChainageToMeters($this->chainage_from);
                $range['end'] = $this->parseChainageToMeters($this->chainage_to);
            }

            if (empty($specificMeters) && ($range['start'] === null || $range['end'] === null)) {
                return collect([]);
            }
        }

        // Get RFIs with valid locations and filter by matching
        return DailyWork::query()
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->limit($limit * 5) // Fetch more to filter
            ->get()
            ->filter(function ($rfi) use ($specificMeters, $range) {
                return $this->doesObjectionMatchRfi(
                    $specificMeters,
                    $range['start'],
                    $range['end'],
                    $rfi->location
                );
            })
            ->take($limit)
            ->values();
    }
}
