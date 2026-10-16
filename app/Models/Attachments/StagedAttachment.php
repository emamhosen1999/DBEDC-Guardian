<?php

namespace App\Models\Attachments;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** An uploaded or scanned document waiting (private disk, 24 h) to be attached to an application. */
class StagedAttachment extends Model
{
    public const KIND_UPLOAD = 'upload';

    public const KIND_SCAN_PAGE = 'scan_page';

    public const KIND_SCAN_PDF = 'scan_pdf';

    protected $fillable = [
        'token', 'user_id', 'kind', 'path', 'original_path', 'mime', 'original_name', 'size', 'page_count', 'mode',
        'corners', 'preview_paths', 'confidence', 'clarity', 'clarity_passed', 'attached_at', 'expires_at',
    ];

    protected $casts = [
        'corners' => 'array', 'preview_paths' => 'array', 'clarity' => 'array', 'clarity_passed' => 'boolean', 'size' => 'integer', 'page_count' => 'integer',
        'confidence' => 'float', 'attached_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public static function disk(): Filesystem
    {
        return Storage::disk(config('attachments.disk'));
    }

    public function absolutePath(): string
    {
        return self::disk()->path($this->path);
    }

    /** The owner may still LOOK at a staged file after it was combined into a PDF, until it expires. */
    public function scopeViewableBy($query, string $userId)
    {
        return $query->where('user_id', $userId)->where('expires_at', '>', now());
    }

    public function scopeUsableBy($query, string $userId)
    {
        return $query->where('user_id', $userId)->whereNull('attached_at')->where('expires_at', '>', now());
    }
}
