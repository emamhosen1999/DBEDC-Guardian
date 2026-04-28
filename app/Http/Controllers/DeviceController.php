<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DeviceAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DeviceController extends Controller
{
    protected DeviceAuthService $deviceAuthService;

    public function __construct(DeviceAuthService $deviceAuthService)
    {
        $this->deviceAuthService = $deviceAuthService;
    }

    /**
     * Get all devices for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $devices = collect($this->deviceAuthService->getUserDevices($user));

        return response()->json([
            'success' => true,
            'devices' => $devices,
            'summary' => $this->buildDeviceSummary($devices),
            'user_state' => $this->buildUserDeviceState($user),
        ]);
    }

    /**
     * Get all devices for a specific user (admin only).
     */
    public function getUserDevices(Request $request, int $userId): JsonResponse|InertiaResponse
    {
        // Authorization check should be done via middleware or policy
        $user = User::findOrFail($userId);
        $devices = collect($this->deviceAuthService->getUserDevices($user));

        $userState = $this->buildUserDeviceState($user);
        $summary = $this->buildDeviceSummary($devices);

        // If request expects JSON (API call), return JSON
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'devices' => $devices,
                'summary' => $summary,
                'user_state' => $userState,
            ]);
        }

        // Otherwise return Inertia page for browser navigation
        return Inertia::render('UserDevices', [
            'user' => $user,
            'devices' => $devices,
            'summary' => $summary,
            'userState' => $userState,
        ]);
    }

    /**
     * Reset all devices for a user (admin only).
     */
    public function resetDevices(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($userId);
        $resetReason = trim((string) $request->input('reason', ''));
        $persistedResetReason = $resetReason !== ''
            ? $resetReason
            : 'Admin reset via user device management';

        $count = $this->deviceAuthService->resetUserDevices($user, $persistedResetReason);
        $this->deviceAuthService->terminateUserAccess($user);

        $user->update([
            'device_reset_at' => now(),
            'device_reset_reason' => $persistedResetReason,
        ]);

        $devices = collect($this->deviceAuthService->getUserDevices($user->fresh()));

        return response()->json([
            'success' => true,
            'message' => "Successfully reset {$count} device(s) for user {$user->email}.",
            'devices_reset' => $count,
            'summary' => $this->buildDeviceSummary($devices),
            'user_state' => $this->buildUserDeviceState($user->fresh()),
        ]);
    }

    /**
     * Deactivate a specific device (user or admin).
     */
    public function deactivateDevice(Request $request, int $deviceId): JsonResponse
    {
        $user = Auth::user();

        // Check if this device belongs to the user
        $success = $this->deviceAuthService->deactivateDevice($user, $deviceId);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found or unauthorized.',
            ], 404);
        }

        $devices = collect($this->deviceAuthService->getUserDevices($user));

        return response()->json([
            'success' => true,
            'message' => 'Device deactivated successfully.',
            'summary' => $this->buildDeviceSummary($devices),
            'user_state' => $this->buildUserDeviceState($user->fresh()),
        ]);
    }

    /**
     * Deactivate a specific device for any user (admin only).
     */
    public function adminDeactivateDevice(Request $request, int $userId, int $deviceId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $success = $this->deviceAuthService->deactivateDevice($user, $deviceId);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found.',
            ], 404);
        }

        if ($user->hasSingleDeviceLoginEnabled()) {
            $this->deviceAuthService->terminateUserAccess($user);
        }

        $devices = collect($this->deviceAuthService->getUserDevices($user));

        return response()->json([
            'success' => true,
            'message' => 'Device deactivated successfully.',
            'summary' => $this->buildDeviceSummary($devices),
            'user_state' => $this->buildUserDeviceState($user->fresh()),
        ]);
    }

    /**
     * Toggle single device login for a user (admin only).
     */
    public function toggleSingleDeviceLogin(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        // Toggle the setting
        $newStatus = ! $user->single_device_login_enabled;

        if ($newStatus) {
            $user->enableSingleDeviceLogin('Enabled by admin');

            $lockedDevice = $user->activeDevices()
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->first();

            if ($lockedDevice) {
                $this->deviceAuthService->enforceSingleDeviceSession($user->fresh(), $lockedDevice);
            } else {
                $this->deviceAuthService->terminateUserAccess($user->fresh());
            }
        } else {
            $user->disableSingleDeviceLogin('Disabled by admin');
        }

        $freshUser = $user->fresh();
        $devices = collect($this->deviceAuthService->getUserDevices($freshUser));

        return response()->json([
            'success' => true,
            'message' => 'Single device login '.($newStatus ? 'enabled' : 'disabled').' successfully.',
            'single_device_login_enabled' => $newStatus,
            'summary' => $this->buildDeviceSummary($devices),
            'user_state' => $this->buildUserDeviceState($freshUser),
        ]);
    }

    /**
     * Build lightweight user state payload for device UI synchronization.
     *
     * @return array<string, mixed>
     */
    protected function buildUserDeviceState(User $user): array
    {
        return [
            'id' => $user->id,
            'single_device_login_enabled' => (bool) $user->single_device_login_enabled,
            'device_reset_at' => $user->device_reset_at,
            'device_reset_reason' => $user->device_reset_reason,
        ];
    }

    /**
     * Build summary metrics for the device management dashboard.
     *
     * @return array<string, int>
     */
    protected function buildDeviceSummary(Collection $devices): array
    {
        $total = $devices->count();
        $active = $devices->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => max($total - $active, 0),
            'trusted' => $devices->where('is_trusted', true)->count(),
        ];
    }
}
