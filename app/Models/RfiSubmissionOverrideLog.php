<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfiSubmissionOverrideLog extends Model
{
    /**
     * Disable auto timestamps since we only use created_at
     */
    public $timestamps = false;

    protected $fillable = [
        'daily_work_id',
        'old_submission_date',
        'new_submission_date',
        'active_objections_count',
        'override_reason',
        'user_acknowledged',
        'overridden_by',
        'created_at',
    ];

    protected $casts = [
        'old_submission_date' => 'date',
        'new_submission_date' => 'date',
        'user_acknowledged' => 'boolean',
        'active_objections_count' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Get the daily work (RFI) this override is for.
     */
    public function dailyWork(): BelongsTo
    {
        return $this->belongsTo(DailyWork::class);
    }

    /**
     * Get the user who made the override.
     */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * Create an override log entry.
     */
    public static function logOverride(
        int $dailyWorkId,
        ?string $oldDate,
        string $newDate,
        int $activeObjectionsCount,
        string $reason,
        string $userId
    ): self {
        return self::create([
            'daily_work_id' => $dailyWorkId,
            'old_submission_date' => $oldDate,
            'new_submission_date' => $newDate,
            'active_objections_count' => $activeObjectionsCount,
            'override_reason' => $reason,
            'user_acknowledged' => true,
            'overridden_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
