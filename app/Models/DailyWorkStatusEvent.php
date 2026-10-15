<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A timestamped status / inspection-result transition of a daily work: the source of turnaround and cycle time. Append-only. */
class DailyWorkStatusEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['daily_work_id', 'from_status', 'to_status', 'from_result', 'to_result', 'actor_id', 'source', 'import_batch_id', 'reason', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A status event is append-only.'));
        static::deleting(fn () => throw new LogicException('A status event cannot be deleted.'));
    }
}
