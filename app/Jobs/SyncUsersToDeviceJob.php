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
        Log::info('Starting bulk user sync job', [
            'device_id' => $this->device->id,
            'device_serial' => $this->device->serial_number,
        ]);

        // Get all active employees with employee_id
        $employees = User::where('active', true)
            ->whereNotNull('employee_id')
            ->orderBy('employee_id')
            ->get();

        if ($employees->isEmpty()) {
            Log::warning('No active employees found for sync', [
                'device_id' => $this->device->id,
            ]);
            return;
        }

        // Get existing device user IDs to avoid duplicates
        $existingDeviceUsers = DB::table('biometric_device_users')
            ->where('biometric_device_id', $this->device->id)
            ->pluck('device_user_id')
            ->toArray();

        $syncedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($employees as $employee) {
            $deviceUserId = $employee->employee_id;

            // Skip if user already exists on device
            if (in_array($deviceUserId, $existingDeviceUsers)) {
                $skippedCount++;
                continue;
            }

            try {
                DB::beginTransaction();

                // Create ADD_USER command
                BiometricDeviceCommand::create([
                    'biometric_device_id' => $this->device->id,
                    'command_type' => 'ADD_USER',
                    'payload' => [
                        'pin' => $deviceUserId,
                        'name' => $employee->name,
                        'card' => $employee->rfid_card ?? '',
                        'privilege' => 0,
                    ],
                    'status' => 'pending',
                ]);

                // Create mapping entry (auto-link device user to system user)
                DB::table('biometric_device_users')->insert([
                    'biometric_device_id' => $this->device->id,
                    'user_id' => $employee->id,
                    'device_user_id' => $deviceUserId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::commit();
                $syncedCount++;
            } catch (\Exception $e) {
                DB::rollBack();
                $errorCount++;
                Log::error('Failed to sync user to device', [
                    'device_id' => $this->device->id,
                    'user_id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Bulk user sync job completed', [
            'device_id' => $this->device->id,
            'device_serial' => $this->device->serial_number,
            'synced' => $syncedCount,
            'skipped' => $skippedCount,
            'errors' => $errorCount,
            'total_employees' => $employees->count(),
        ]);
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
