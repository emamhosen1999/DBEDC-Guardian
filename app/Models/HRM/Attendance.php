<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Attendance extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected static function newFactory(): \Database\Factories\HRM\AttendanceFactory
    {
        return \Database\Factories\HRM\AttendanceFactory::new();
    }

    protected $fillable = [
        'user_id',
        'date',
        'punchin',
        'punchout',
        'punchin_location',
        'punchout_location',
        'symbol',
        'policy_status',
        'needs_approval',
        'policy_exception_reason',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'punchin' => 'datetime',
        'punchout' => 'datetime',
    ];

    protected $appends = [
        'punchin_photo_url',
        'punchout_photo_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Register media collections for punch photos
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('punchin_photo')
            ->singleFile();

        $this->addMediaCollection('punchout_photo')
            ->singleFile();
    }

    /**
     * Register media conversions for thumbnails
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->sharpen(10);
    }

    /**
     * Get punch in photo URL
     */
    public function getPunchinPhotoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('punchin_photo') ?: null;
    }

    /**
     * Get punch out photo URL
     */
    public function getPunchoutPhotoUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('punchout_photo') ?: null;
    }

    /**
     * Get punch in location as array
     */
    public function getPunchinLocationArrayAttribute(): ?array
    {
        return $this->punchin_location ? json_decode($this->punchin_location, true) : null;
    }

    /**
     * Get punch out location as array
     */
    public function getPunchoutLocationArrayAttribute(): ?array
    {
        return $this->punchout_location ? json_decode($this->punchout_location, true) : null;
    }
}
