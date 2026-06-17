<?php

namespace App\Jobs;

use App\Models\HRM\BiometricDownloadSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBiometricDownloadSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 360;

    protected BiometricDownloadSession $session;

    /**
     * Create a new job instance.
     */
    public function __construct(BiometricDownloadSession $session)
    {
        $this->session = $session;
        $this->queue = 'biometric';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->session->refresh();

        // If already completed or failed, we are done
        if (in_array($this->session->status, ['completed', 'failed', 'partial'])) {
            return;
        }

        // If it's been more than 5 minutes since the session was created, time out
        if ($this->session->created_at->addMinutes(5)->isPast()) {
            $this->session->markFailed('Timeout: The device did not respond within 5 minutes.');
            if ($this->session->command) {
                $this->session->command->markAsFailed('Timeout: Device did not request this command within 5 minutes.');
            }

            return;
        }

        // Check if command is still pending/sent
        $command = $this->session->command;
        if ($command) {
            if ($command->status === 'pending') {
                // Device hasn't connected to pick it up yet. Let's release the job to check again in 15 seconds.
                $this->release(15);

                return;
            }

            if ($command->status === 'sent') {
                // Command has been sent, device is processing or pushing logs.
                if ($this->session->status === 'pending') {
                    $this->session->markInProgress();
                }
                // Release to check again in 15 seconds.
                $this->release(15);

                return;
            }

            if ($command->status === 'failed') {
                $this->session->markFailed('Device failed to execute the download command: '.($command->error_message ?? 'Unknown device error'));

                return;
            }

            if ($command->status === 'executed') {
                // Handled by the webhook callback, but as a fallback/failsafe:
                if ($this->session->status === 'in_progress' || $this->session->status === 'pending') {
                    if ($this->session->failed_count > 0 && $this->session->processed_count > 0) {
                        $this->session->markPartial();
                    } elseif ($this->session->failed_count > 0 && $this->session->processed_count == 0 && $this->session->total_records > 0) {
                        $this->session->markFailed('Completed with errors. No records were processed successfully.');
                    } else {
                        $this->session->markCompleted();
                    }
                }

                return;
            }
        } else {
            // No command linked? Something went wrong
            $this->session->markFailed('No active command linked to this session.');
        }
    }
}
