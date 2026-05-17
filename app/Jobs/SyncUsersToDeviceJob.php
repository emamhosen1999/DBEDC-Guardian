<?php

namespace App\Jobs;

use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncUsersToDeviceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300; // 5 minutes

    protected $device;

    public function __construct(BiometricDevice $device)
    {
        $this->device = $device;
    }

    public function handle()
    {
        Log::warning('SyncUsersToDeviceJob is deprecated - user sync no longer needed', [
            'device_id' => $this->device->id,
            'device_serial' => $this->device->serial_number,
        ]);

        // User sync is no longer needed - devices use employee_id directly
        // This job is kept for compatibility but does nothing
        return;
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Bulk user sync job failed', [
            'device_id' => $this->device->id,
            'device_serial' => $this->device->serial_number,
            'error' => $exception->getMessage(),
        ]);
    }
}
