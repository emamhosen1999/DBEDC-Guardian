<?php

namespace App\Models;

use App\Models\HRM\AttendanceType;
use App\Models\HRM\EmployeeAttendanceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkLocation extends Model
{
    use HasFactory;
    use SoftDeletes;

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
        'code',
        'description',
        'address',
        'latitude',
        'longitude',
        'geofence_radius',
        'timezone',
        'is_active',
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
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_radius' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the default attendance rule for this location.
     */
    public function attendanceType(): BelongsTo
    {
        return $this->belongsTo(AttendanceType::class, 'attendance_type_id');
    }

    /**
     * Multi-method: the SET of allowed attendance methods at this location.
     * Employees here inherit this set unless they have a personal override set.
     */
    public function attendanceTypes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(AttendanceType::class, 'work_location_attendance_type');
    }

    /**
     * Employees assigned to this work location.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'work_location_id');
    }

    protected static function booted()
    {
        static::saved(function ($workLocation) {
            if ($workLocation->isDirty('attendance_type_id')) {
                // Propagate the new default rule to every employee in this location
                // who does not have a personal override. updateOrCreate ensures
                // employees without an existing row still receive the inherited rule.
                $userIds = User::where('work_location_id', $workLocation->id)
                    ->whereNull('attendance_type_id')
                    ->pluck('id');

                foreach ($userIds as $userId) {
                    EmployeeAttendanceType::updateOrCreate(
                        ['user_id' => $userId],
                        ['attendance_type_id' => $workLocation->attendance_type_id]
                    );
                }
            }
        });
    }
}
