<?php

namespace App\Console\Commands;

use App\Models\HRM\BiometricDeviceCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledBiometricCommands extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biometric:process-scheduled-commands';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process scheduled biometric device commands that are due';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing scheduled biometric commands...');

        $dueCommands = BiometricDeviceCommand::due()->get();

        if ($dueCommands->isEmpty()) {
            $this->info('No scheduled commands due.');
            return 0;
        }

        $this->info("Found {$dueCommands->count()} scheduled command(s) due.");

        foreach ($dueCommands as $command) {
            $this->info("Processing command #{$command->id}: {$command->command_type} for device {$command->biometric_device_id}");

            // Clear the scheduled_at to mark it as ready for immediate processing
            $command->update(['scheduled_at' => null]);

            Log::info('Scheduled command released for processing', [
                'command_id' => $command->id,
                'command_type' => $command->command_type,
                'device_id' => $command->biometric_device_id,
            ]);

            $this->info("Command #{$command->id} released for processing.");
        }

        $this->info("Processed {$dueCommands->count()} scheduled command(s).");

        return 0;
    }
}
