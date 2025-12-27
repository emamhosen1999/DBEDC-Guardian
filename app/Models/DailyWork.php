<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DailyWork extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    // Status constants
    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in-progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RESUBMISSION = 'resubmission';

    public const STATUS_PENDING = 'pending';

    public const STATUS_EMERGENCY = 'emergency';

    // Inspection result constants
    public const INSPECTION_PASS = 'pass';

    public const INSPECTION_FAIL = 'fail';

    public const INSPECTION_CONDITIONAL = 'conditional';

    public const INSPECTION_PENDING = 'pending';

    public const INSPECTION_APPROVED = 'approved';

    public const INSPECTION_REJECTED = 'rejected';

    // Type constants
    public const TYPE_EMBANKMENT = 'Embankment';

    public const TYPE_STRUCTURE = 'Structure';

    public const TYPE_PAVEMENT = 'Pavement';

    // RFI Response Status constants
    public const RFI_RESPONSE_APPROVED = 'approved';

    public const RFI_RESPONSE_REJECTED = 'rejected';

    public const RFI_RESPONSE_RETURNED = 'returned';

    public const RFI_RESPONSE_CONCURRED = 'concurred';

    public const RFI_RESPONSE_NOT_CONCURRED = 'not_concurred';

    /**
     * Valid statuses for validation
     *
     * @var array<string>
     */
    public static array $statuses = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_RESUBMISSION,
        self::STATUS_PENDING,
        self::STATUS_EMERGENCY,
    ];

    /**
     * Valid inspection results for validation
     *
     * @var array<string>
     */
    public static array $inspectionResults = [
        self::INSPECTION_PASS,
        self::INSPECTION_FAIL,
        self::INSPECTION_CONDITIONAL,
        self::INSPECTION_PENDING,
        self::INSPECTION_APPROVED,
        self::INSPECTION_REJECTED,
    ];

    /**
     * Valid work types for validation
     *
     * @var array<string>
     */
    public static array $types = [
        self::TYPE_EMBANKMENT,
        self::TYPE_STRUCTURE,
        self::TYPE_PAVEMENT,
    ];

    /**
     * Valid RFI response statuses for validation
     *
     * @var array<string>
     */
    public static array $rfiResponseStatuses = [
        self::RFI_RESPONSE_APPROVED,
        self::RFI_RESPONSE_REJECTED,
        self::RFI_RESPONSE_RETURNED,
        self::RFI_RESPONSE_CONCURRED,
        self::RFI_RESPONSE_NOT_CONCURRED,
    ];

    /**
     * Valid side/road types for validation
     *
     * @var array<string>
     */
    public static array $sides = [
        'TR-R',
        'TR-L',
        'SR-R',
        'SR-L',
        'Both',
    ];

    protected $fillable = [
        'date',
        'number',
        'status',
        'inspection_result',
        'rfi_response_status',
        'rfi_response_date',
        'type',
        'description',
        'location',
        'side',
        'qty_layer',
        'planned_time',
        'incharge',
        'assigned',
        'completion_time',
        'inspection_details',
        'resubmission_count',
        'resubmission_date',
        'rfi_submission_date',
    ];

    protected $casts = [
        'date' => 'date',
        'completion_time' => 'datetime',
        'rfi_submission_date' => 'date',
        'rfi_response_date' => 'date',
        'resubmission_count' => 'integer',
    ];

    /**
     * Append RFI files count and objection info to JSON serialization.
     * Note: rfi_files is not appended by default to avoid N+1 queries.
     * Use getRfiFilesAttribute() explicitly when needed.
     * Note: active_objections_count is loaded via withCount in services, not appended.
     */
    protected $appends = ['rfi_files_count', 'has_active_objections'];

    /**
     * Register media collections for RFI files.
     * Supports multiple files (images and PDFs) per daily work.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('rfi_files')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'application/pdf',
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
            ->performOnCollections('rfi_files');
    }

    /**
     * Get RFI files count.
     */
    public function getRfiFilesCountAttribute(): int
    {
        return $this->getMedia('rfi_files')->count();
    }

    /**
     * Get RFI files with formatted data.
     */
    public function getRfiFilesAttribute(): array
    {
        return $this->getMedia('rfi_files')->map(function ($media) {
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

    // Relationships
    public function reports()
    {
        return $this->belongsToMany(Report::class, 'daily_work_has_report', 'daily_work_id', 'report_id');
    }

    public function inchargeUser()
    {
        return $this->belongsTo(User::class, 'incharge');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned');
    }

    /**
     * Get all objections for this RFI (many-to-many).
     */
    public function objections()
    {
        return $this->belongsToMany(RfiObjection::class, 'daily_work_objection')
            ->withPivot(['attached_by', 'attached_at', 'attachment_notes'])
            ->withTimestamps()
            ->orderBy('daily_work_objection.attached_at', 'desc');
    }

    /**
     * Get only active (blocking) objections for this RFI.
     */
    public function activeObjections()
    {
        return $this->belongsToMany(RfiObjection::class, 'daily_work_objection')
            ->withPivot(['attached_by', 'attached_at', 'attachment_notes'])
            ->withTimestamps()
            ->whereIn('rfi_objections.status', RfiObjection::$activeStatuses)
            ->orderBy('daily_work_objection.attached_at', 'desc');
    }

    /**
     * Get submission override logs for this RFI.
     */
    public function submissionOverrideLogs()
    {
        return $this->hasMany(RfiSubmissionOverrideLog::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get count of active objections.
     * Uses eager loaded withCount value if available, otherwise queries.
     */
    public function getActiveObjectionsCountAttribute(): int
    {
        // Prefer the eager loaded withCount value if available
        if (array_key_exists('active_objections_count', $this->attributes)) {
            return (int) $this->attributes['active_objections_count'];
        }

        // Fallback to query if not eager loaded
        return $this->objections()
            ->whereIn('status', RfiObjection::$activeStatuses)
            ->count();
    }

    /**
     * Check if RFI has any active objections.
     * Uses eager loaded count first for performance.
     */
    public function getHasActiveObjectionsAttribute(): bool
    {
        // Prefer the eager loaded withCount value if available
        if (array_key_exists('active_objections_count', $this->attributes)) {
            return (int) $this->attributes['active_objections_count'] > 0;
        }

        // Fallback to exists check
        return $this->activeObjections()->exists();
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', '!=', 'completed');
    }

    public function scopeWithRFI($query)
    {
        return $query->whereNotNull('rfi_submission_date');
    }

    public function scopeResubmissions($query)
    {
        return $query->where('resubmission_count', '>', 0);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByIncharge($query, $inchargeId)
    {
        return $query->where('incharge', $inchargeId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to RFIs with active objections.
     */
    public function scopeWithActiveObjections($query)
    {
        return $query->whereHas('objections', function ($q) {
            $q->whereIn('status', RfiObjection::$activeStatuses);
        });
    }

    /**
     * Scope to RFIs without active objections.
     */
    public function scopeWithoutActiveObjections($query)
    {
        return $query->whereDoesntHave('objections', function ($q) {
            $q->whereIn('status', RfiObjection::$activeStatuses);
        });
    }

    // Accessors
    public function getIsCompletedAttribute()
    {
        return $this->status === 'completed';
    }

    public function getHasRfiSubmissionAttribute()
    {
        return ! is_null($this->rfi_submission_date);
    }

    public function getIsResubmissionAttribute()
    {
        return $this->resubmission_count > 0;
    }

    /**
     * Check if a status is valid.
     */
    public static function isValidStatus(?string $status): bool
    {
        if ($status === null) {
            return true;
        }

        return in_array($status, self::$statuses, true);
    }

    /**
     * Check if an inspection result is valid.
     */
    public static function isValidInspectionResult(?string $result): bool
    {
        if ($result === null) {
            return true;
        }

        return in_array($result, self::$inspectionResults, true);
    }

    /**
     * Boot method with validation.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($dailyWork) {
            // Validate status
            if ($dailyWork->status && ! self::isValidStatus($dailyWork->status)) {
                throw new \InvalidArgumentException(
                    "Invalid status '{$dailyWork->status}'. Valid statuses are: ".implode(', ', self::$statuses)
                );
            }

            // Validate inspection_result
            if ($dailyWork->inspection_result && ! self::isValidInspectionResult($dailyWork->inspection_result)) {
                throw new \InvalidArgumentException(
                    "Invalid inspection result '{$dailyWork->inspection_result}'. Valid results are: ".implode(', ', self::$inspectionResults)
                );
            }
        });

        // Detach all objections when soft-deleting to prevent orphan pivot entries
        static::deleting(function ($dailyWork) {
            $dailyWork->objections()->detach();
        });
    }
}
