<?php

namespace App\Models\HRM;

use App\Models\User;
use App\Services\FeatureFlagService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Offboarding extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress'; // changed from in-progress

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    // Reason constants
    public const REASON_RESIGNATION = 'resignation';

    public const REASON_TERMINATION = 'termination';

    public const REASON_RETIREMENT = 'retirement';

    public const REASON_END_CONTRACT = 'end-of-contract';

    public const REASON_OTHER = 'other';

    public const REASON_ABSCONDED = 'absconded';

    public const REASON_RESIGNATION_WITHOUT_NOTICE = 'resignation_without_notice';

    protected $fillable = [
        'employee_id',
        'initiation_date',
        'last_working_date',
        'exit_interview_date',
        'resignation_received_at',
        'notice_days_required',
        'notice_shortfall_days',
        'reason',
        'status',
        'notes',
        'lwd_processed_at',
        // created_by / updated_by handled via hooks
    ];

    protected $casts = [
        'initiation_date' => 'date',
        'last_working_date' => 'date',
        'exit_interview_date' => 'date',
        'resignation_received_at' => 'datetime',
        'lwd_processed_at' => 'datetime',
        'notice_days_required' => 'integer',
        'notice_shortfall_days' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->status)) {
                $model->status = self::STATUS_PENDING;
            }
            // An explicitly assigned created_by (system actor, e.g. the absence-streak
            // command running with no session) wins; otherwise stamp the signed-in user.
            if (empty($model->created_by) && Auth::id()) {
                $model->created_by = Auth::id();
            }
        });
        static::updating(function (self $model) {
            if (Auth::id()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OffboardingTask::class);
    }

    public function finalSettlement(): HasOne
    {
        return $this->hasOne(FinalSettlement::class);
    }

    // Accessors / Helpers
    public function getProgressAttribute(): float
    {
        $total = $this->tasks->count();
        if ($total === 0) {
            return 0.0;
        }
        $completed = $this->tasks->where('status', 'completed')->count();

        return round(($completed / $total) * 100, 2);
    }

    public function isCompletable(): bool
    {
        return $this->tasks()->whereNotIn('status', ['completed', 'not-applicable'])->count() === 0;
    }

    /**
     * The instant the last working day is over (start of the following day,
     * app timezone). LWD effects (access revocation, biometric removal, roster
     * cleanup) must not run before this moment.
     */
    public function lwdEndsAt(): ?Carbon
    {
        return $this->last_working_date?->copy()->startOfDay()->addDay();
    }

    public function lwdHasPassed(): bool
    {
        $endsAt = $this->lwdEndsAt();

        return $endsAt !== null && now()->gte($endsAt);
    }

    /**
     * Human-readable reasons this offboarding cannot be marked completed yet.
     *
     * @return array<int, string>
     */
    public function completionBlockers(): array
    {
        $blockers = [];

        if (! $this->lwdHasPassed()) {
            $blockers[] = 'The last working date ('.($this->last_working_date?->toDateString() ?? 'not set').') has not passed yet.';
        }

        $pendingTasks = $this->tasks()
            ->whereNotIn('status', [OffboardingTask::STATUS_COMPLETED, OffboardingTask::STATUS_NOT_APPLICABLE])
            ->count();
        if ($pendingTasks > 0) {
            $blockers[] = "{$pendingTasks} clearance task(s) are still pending.";
        }

        $assets = Asset::where('assignee_id', $this->employee_id)
            ->where('status', Asset::STATUS_ASSIGNED)
            ->count();
        if ($assets > 0) {
            $blockers[] = "{$assets} company asset(s) are still assigned to the employee.";
        }

        // F&F is behind a feature flag; only enforce it once the module is live.
        if (app(FeatureFlagService::class)->isEnabled('hr_final_settlement', null, false)) {
            $settlement = $this->finalSettlement()->first();
            if ($settlement && $settlement->status !== FinalSettlement::STATUS_PAID) {
                $blockers[] = 'The final settlement exists but has not been paid.';
            }
        }

        return $blockers;
    }

    public function getStatusAttribute($value)
    {
        return $value === 'in-progress' ? self::STATUS_IN_PROGRESS : $value;
    }

    public function setStatusAttribute($value)
    {
        $this->attributes['status'] = $value === 'in-progress' ? self::STATUS_IN_PROGRESS : $value;
    }
}
