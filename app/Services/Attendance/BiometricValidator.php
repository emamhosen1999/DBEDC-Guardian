<?php

namespace App\Services\Attendance;

use App\Models\HRM\BiometricDevice;
use Illuminate\Support\Facades\DB;

/**
 * Validator for biometric device attendance type.
 * Verifies device is registered + active and the device_user_id is mapped.
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

        // Resolve user by matching device_user_id to employee_id
        $user = User::where('employee_id', $deviceUserId)->first();

        if (! $user) {
            return $this->errorResponse('User not found with employee_id: ' . $deviceUserId, 404);
        }

        return $this->successResponse('Biometric validation successful.', [
            'device_id'  => $device->id,
            'user_id'    => $user->id,
        ]);
    }
}
