<?php

namespace App\Models;

use App\Models\HRM\AttendanceType;
use App\Models\HRM\EmployeeAttendanceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkLocation extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'work_locations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'attendance_type_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'attendance_type_id' => 'integer',
    ];

    /**
     * Get the default attendance rule for this location.
     */
    public function attendanceType(): BelongsTo
    {
        return $this->belongsTo(AttendanceType::class, 'attendance_type_id');
    }

    protected static function booted()
    {
        static::saved(function ($workLocation) {
            if ($workLocation->isDirty('attendance_type_id')) {
                // Find all users in this location who do not have a custom override
                $userIds = User::where('work_location_id', $workLocation->id)
                    ->whereNull('attendance_type_id')
                    ->pluck('id');

                if ($userIds->isNotEmpty()) {
                    EmployeeAttendanceType::whereIn('user_id', $userIds)
                        ->update(['attendance_type_id' => $workLocation->attendance_type_id]);
                }
            }
        });
    }
}
