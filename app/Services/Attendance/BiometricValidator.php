<?php

namespace App\Services\Attendance;

use App\Models\HRM\BiometricDevice;
use App\Models\HRM\AttendanceType;
use App\Models\User;

/**
 * Validator for biometric device attendance type.
 *
 * Logic:
 *  1. Device must be registered and active.
 *  2. User must exist and have an attendance type whose slug starts with "biometric".
 *  3. That attendance type must have at least one biometric device linked via the
 *     attendance_type_biometric_device pivot table.
 *  4. The punching device must be one of those linked devices (zone/group model).
 *     If no devices are linked to the AT yet, any active device is accepted.
 */
class BiometricValidator extends BaseAttendanceValidator
{
    public function validate(): array
    {
        $serialNumber = $this->request->input('device_serial');
        $deviceUserId = $this->request->input('device_user_id');

        if (! $serialNumber || ! $deviceUserId) {
            return $this->errorResponse('Missing biometric device credentials.', 422);
        }

        $device = BiometricDevice::where('serial_number', $serialNumber)->first();

        if (! $device) {
            return $this->errorResponse('Biometric device not registered.', 403);
        }

        if (! $device->is_active) {
            return $this->errorResponse('Biometric device is inactive.', 403);
        }

        $user = User::with('employeeAttendanceType')->where('employee_id', $deviceUserId)->first();

        if (! $user) {
            return $this->errorResponse('User not found with employee_id: ' . $deviceUserId, 404);
        }

        if (! $user->attendance_type_id) {
            return $this->errorResponse('User does not have an attendance type assigned.', 403);
        }

        $attendanceType = AttendanceType::find($user->attendance_type_id);

        if (! $attendanceType) {
            return $this->errorResponse("User's attendance type not found.", 403);
        }

        if (! str_starts_with($attendanceType->slug, 'biometric')) {
            return $this->errorResponse("User's attendance type is not biometric. Device punches not allowed.", 403);
        }

        // Employee must have a specific device assigned — no pool fallback
        $eat = $user->employeeAttendanceType;
        if (! $eat || ! $eat->biometric_device_id) {
            return $this->errorResponse(
                'No biometric device assigned to this employee. Please assign a device first.',
                403
            );
        }

        if ($eat->biometric_device_id !== $device->id) {
            return $this->errorResponse(
                'Punch rejected: employee is assigned to a different device.',
                403
            );
        }

        return $this->successResponse('Biometric validation successful.', [
            'device_id' => $device->id,
            'user_id'   => $user->id,
        ]);
    }
}
