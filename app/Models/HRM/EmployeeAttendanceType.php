<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendanceType extends Model
{
    protected $table = 'employee_attendance_types';

    protected $fillable = [
        'user_id',
        'attendance_type_id',
        'biometric_device_id',
    ];

    protected $casts = [
        'user_id'             => 'integer',
        'attendance_type_id'  => 'integer',
        'biometric_device_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceType(): BelongsTo
    {
        return $this->belongsTo(AttendanceType::class);
    }

    public function biometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class);
    }
}
