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

        $mapping = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('device_user_id', $deviceUserId)
            ->where('is_active', true)
            ->first();

        if (! $mapping) {
            return $this->errorResponse('User not enrolled on this biometric device.', 403);
        }

        return $this->successResponse('Biometric validation successful.', [
            'device_id'  => $device->id,
            'user_id'    => $mapping->user_id,
        ]);
    }
}
