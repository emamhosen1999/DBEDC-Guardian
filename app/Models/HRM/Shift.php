<?php

namespace App\Models\HRM;

use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        return $this->belongsTo(\App\Models\User::class, 'created_by');
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
        $start = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($this->start_time)->format('H:i:s'));
        $end = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($this->end_time)->format('H:i:s'));

        if ($this->crosses_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return new ShiftSchedule(
            start: $start,
            end: $end,
            crossesMidnight: (bool) $this->crosses_midnight,
            graceInMinutes: $this->grace_in_minutes,
            graceOutMinutes: $this->grace_out_minutes,
            fullDayMinutes: $this->full_day_minutes,
            halfDayMinutes: $this->half_day_minutes,
            minPresentMinutes: $this->min_present_minutes,
            breakMinutes: $this->break_minutes,
            isWorkingDay: true,
        );
    }
}
