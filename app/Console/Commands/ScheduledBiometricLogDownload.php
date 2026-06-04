<?php

namespace App\Console\Commands;

use App\Models\HRM\BiometricDevice;
use App\Services\Biometric\BiometricProcessingService;
use App\Jobs\ProcessBiometricDownloadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScheduledBiometricLogDownload extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometric:scheduled-log-download {--hours=6 : Time threshold in hours since last sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scheduled download of attendance logs from all active ADMS devices';

    protected BiometricProcessingService $biometricService;

    /**
     * Create a new command instance.
     */
    public function __construct(BiometricProcessingService $biometricService)
    {
        parent::__construct();
        $this->biometricService = $biometricService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hoursThreshold = (int) $this->option('hours');
        $this->info("Starting scheduled log download for devices not synced in the last {$hoursThreshold} hour(s)...");

        // Fetch active ADMS devices
        $devices = BiometricDevice::active()->get()->filter(function ($device) {
            return $device->isAdms();
        });

        if ($devices->isEmpty()) {
            $this->info('No active ADMS devices found.');
            return 0;
        }

        $triggeredCount = 0;
        foreach ($devices as $device) {
            // Check if last download was more than threshold hours ago (or never)
            $shouldDownload = is_null($device->last_log_download_at) || 
                              $device->last_log_download_at->lt(now()->subHours($hoursThreshold));

            if (!$shouldDownload) {
                $this->info("Skipping device {$device->name} (serial: {$device->serial_number}) - last synced recently.");
                continue;
            }

            try {
                $this->info("Triggering download for device {$device->name}...");
                $session = $this->biometricService->initiateLogDownload(
                    $device,
                    'scheduled'
                );

                // Dispatch monitoring job
                ProcessBiometricDownloadSession::dispatch($session);

                $triggeredCount++;
            } catch (\Exception $e) {
                $this->error("Failed to trigger download for device {$device->name}: " . $e->getMessage());
                Log::error("Scheduled log download failed for device {$device->id}: " . $e->getMessage());
            }
        }

        $this->info("Triggered downloads for {$triggeredCount} device(s).");
        return 0;
    }
}
