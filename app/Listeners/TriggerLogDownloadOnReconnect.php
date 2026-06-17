<?php

namespace App\Listeners;

use App\Events\BiometricDeviceConnected;
use App\Jobs\ProcessBiometricDownloadSession;
use App\Services\Biometric\BiometricProcessingService;
use Illuminate\Support\Facades\Log;

class TriggerLogDownloadOnReconnect
{
    protected BiometricProcessingService $biometricService;

    public function __construct(BiometricProcessingService $biometricService)
    {
        $this->biometricService = $biometricService;
    }

    public function handle(BiometricDeviceConnected $event): void
    {
        $device = $event->device;

        // Verify device is active and uses ADMS protocol
        if (! $device->is_active || ! $device->isAdms()) {
            return;
        }

        // Only trigger reconnect download if device has been offline for > 5 minutes (or heartbeat is null)
        $isReconnect = is_null($device->last_heartbeat_at) ||
                       $device->last_heartbeat_at->lt(now()->subMinutes(5));

        if (! $isReconnect) {
            return;
        }

        Log::info('Device reconnected after disconnect interval; triggering automatic log sync', [
            'device_id' => $device->id,
            'serial' => $device->serial_number,
        ]);

        try {
            $session = $this->biometricService->initiateLogDownload(
                $device,
                'reconnect'
            );

            // Dispatch monitoring job
            ProcessBiometricDownloadSession::dispatch($session);

        } catch (\Exception $e) {
            Log::error('Failed to trigger automatic reconnect download: '.$e->getMessage(), [
                'device_id' => $device->id,
            ]);
        }
    }
}
