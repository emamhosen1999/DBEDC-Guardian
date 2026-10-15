<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry of a daily work's append-only history: what changed (field, before, after), who, when, and from where.
 * Written only by DailyWorkHistoryObserver; the model refuses updates and deletes.
 */
class DailyWorkRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['daily_work_id', 'revision_no', 'previous_revision_id', 'event', 'tags', 'changes', 'source', 'actor_id', 'import_batch_id', 'reason'];

    protected $casts = ['tags' => 'array', 'changes' => 'array', 'revision_no' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A revision is append-only.'));
        static::deleting(fn () => throw new LogicException('A revision cannot be deleted.'));
    }

    public function dailyWork(): BelongsTo
    {
        return $this->belongsTo(DailyWork::class)->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
