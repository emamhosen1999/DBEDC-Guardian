<?php

namespace App\Models\HRM;

use App\Models\User;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'type', 'start_time', 'end_time', 'crosses_midnight',
        'break_minutes', 'grace_in_minutes', 'grace_out_minutes',
        'full_day_minutes', 'half_day_minutes', 'min_present_minutes',
        'core_start_time', 'core_end_time', 'color', 'is_active', 'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ShiftVersion::class);
    }

    /**
     * Resolve the effective-dated version for a given date: the latest
     * version whose effective_from is on or before that date. Falls back
     * to null (callers fall back to the shift's own mirrored columns) so
     * legacy shifts without any version rows never explode.
     */
    public function versionFor(CarbonInterface $date): ?ShiftVersion
    {
        $dateString = $date->copy()->startOfDay()->toDateString();

        if ($this->relationLoaded('versions')) {
            return $this->versions
                ->filter(fn (ShiftVersion $version) => $version->effective_from->toDateString() <= $dateString)
                ->sortByDesc(fn (ShiftVersion $version) => $version->effective_from->toDateString())
                ->first();
        }

        return $this->versions()
            ->where('effective_from', '<=', $dateString)
            ->orderByDesc('effective_from')
            ->first();
    }

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'is_active' => 'boolean',
        'break_minutes' => 'integer',
        'grace_in_minutes' => 'integer',
        'grace_out_minutes' => 'integer',
        'full_day_minutes' => 'integer',
        'half_day_minutes' => 'integer',
        'min_present_minutes' => 'integer',
    ];

    public function toSchedule(CarbonInterface $date): ShiftSchedule
    {
        $day = $date->copy()->startOfDay();
        $version = $this->versionFor($date);

        $startTimeRaw = $version?->start_time ?? $this->start_time;
        $endTimeRaw = $version?->end_time ?? $this->end_time;
        $crossesMidnight = (bool) ($version?->crosses_midnight ?? $this->crosses_midnight);
        $graceInMinutes = (int) ($version?->grace_in_minutes ?? $this->grace_in_minutes);
        $graceOutMinutes = (int) ($version?->grace_out_minutes ?? $this->grace_out_minutes);
        $fullDayMinutes = (int) ($version?->full_day_minutes ?? $this->full_day_minutes);
        $halfDayMinutes = (int) ($version?->half_day_minutes ?? $this->half_day_minutes);
        $minPresentMinutes = (int) ($version?->min_present_minutes ?? $this->min_present_minutes);
        $breakMinutes = (int) ($version?->break_minutes ?? $this->break_minutes);

        $start = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($startTimeRaw)->format('H:i:s'));
        $end = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($endTimeRaw)->format('H:i:s'));

        if ($crossesMidnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return new ShiftSchedule(
            start: $start,
            end: $end,
            crossesMidnight: $crossesMidnight,
            graceInMinutes: $graceInMinutes,
            graceOutMinutes: $graceOutMinutes,
            fullDayMinutes: $fullDayMinutes,
            halfDayMinutes: $halfDayMinutes,
            minPresentMinutes: $minPresentMinutes,
            breakMinutes: $breakMinutes,
            isWorkingDay: true,
        );
    }
}
