<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Excel import: provenance of every work it created or resubmitted, reviewable and reversible (audited, soft). */
class DailyWorkImportBatch extends Model
{
    public const PREVIEWED = 'previewed';

    public const COMMITTED = 'committed';

    public const REVERSED = 'reversed';

    public const FAILED = 'failed';

    protected $fillable = ['token', 'filename', 'file_sha256', 'status', 'sheets', 'rows_total', 'rows_valid', 'rows_warning', 'rows_error', 'rows_created', 'rows_resubmitted', 'rows_skipped', 'summary', 'created_by', 'committed_at', 'reversed_by', 'reversed_at', 'reverse_reason'];

    protected $casts = ['summary' => 'array', 'committed_at' => 'datetime', 'reversed_at' => 'datetime'];

    public function works(): HasMany
    {
        return $this->hasMany(DailyWork::class, 'import_batch_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DailyWorkRevision::class, 'import_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
