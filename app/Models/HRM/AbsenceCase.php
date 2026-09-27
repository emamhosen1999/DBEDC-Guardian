<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceCase extends Model
{
    public const STAGE_MONITORING = 'monitoring';

    public const STAGE_NOTICE_SENT = 'notice_sent';

    public const STAGE_SHOW_CAUSE = 'show_cause';

    public const STAGE_DEEMED_RESIGNATION = 'deemed_resignation';

    public const STAGE_RETURNED = 'returned';

    public const STAGE_ABSCONDED = 'absconded';

    public const OUTCOME_REGULARIZED = 'regularized';

    public const OUTCOME_LWP = 'lwp';

    public const OUTCOME_ABSCONDED = 'absconded';

    public const OUTCOME_RETURNED = 'returned';

    protected $fillable = [
        'user_id',
        'first_absent_date',
        'streak_days',
        'stage',
        'notices_sent',
        'outcome',
        'offboarding_id',
        'timeline',
        'notes',
    ];

    protected $casts = [
        'first_absent_date' => 'date',
        'streak_days' => 'integer',
        'notices_sent' => 'integer',
        'timeline' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id');
    }

    public function offboarding(): BelongsTo
    {
        return $this->belongsTo(Offboarding::class);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, [self::STAGE_RETURNED, self::STAGE_ABSCONDED], true);
    }

    public function addTimelineEntry(string $action, ?string $note = null): void
    {
        $timeline = $this->timeline ?? [];
        $timeline[] = [
            'date' => now()->toDateString(),
            'action' => $action,
            'note' => $note,
        ];
        $this->timeline = $timeline;
    }
}
