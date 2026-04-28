<?php

namespace App\Services\Device;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class DeviceAuditService
{
    /**
     * Log device deactivation
     */
    public function logDeviceDeactivation(User $user, int $deviceId, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'device_deactivated',
            'entity_type' => 'device',
            'entity_id' => $deviceId,
            'target_user_id' => $user->id,
            'description' => "Device {$deviceId} deactivated for user {$user->email}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log device reset (all devices for a user)
     */
    public function logDeviceReset(User $user, int $deviceCount, string $reason, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'devices_reset',
            'entity_type' => 'device',
            'entity_id' => null,
            'target_user_id' => $user->id,
            'new_values' => [
                'device_count' => $deviceCount,
                'reason' => $reason,
            ],
            'description' => "Reset {$deviceCount} device(s) for user {$user->email} (reason: {$reason})",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log single device login toggle
     */
    public function logSingleDeviceLoginToggle(User $user, bool $enabled, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => $enabled ? 'single_device_login_enabled' : 'single_device_login_disabled',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'new_values' => [
                'single_device_login_enabled' => $enabled,
            ],
            'description' => "Single device login ".($enabled ? 'enabled' : 'disabled')." for user {$user->email}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log device access termination
     */
    public function logDeviceAccessTermination(User $user, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'device_access_terminated',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'description' => "Device access terminated for user {$user->email}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log admin device deactivation
     */
    public function logAdminDeviceDeactivation(User $targetUser, int $deviceId, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'admin_device_deactivated',
            'entity_type' => 'device',
            'entity_id' => $deviceId,
            'target_user_id' => $targetUser->id,
            'description' => "Admin deactivated device {$deviceId} for user {$targetUser->email}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Generic log method
     */
    protected function log(array $data): void
    {
        \Log::info('Device Audit Log', $data);
    }
}
