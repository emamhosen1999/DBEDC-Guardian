<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kreait\Laravel\Firebase\Facades\Firebase;

class FirebasePing extends Command
{
    protected $signature = 'firebase:ping';

    protected $description = 'Write + read a test node in Firebase Realtime Database to verify connectivity from this host.';

    public function handle(): int
    {
        try {
            $ref = Firebase::database()->getReference('signals/_ping');
            $ref->set([
                'entity' => 'ping',
                'action' => 'test',
                'actor_id' => 0,
                'ts' => now()->toIso8601String(),
            ]);
            $value = $ref->getValue();

            $this->info('Firebase RTDB write OK: signals/_ping');
            $this->line('Read back: '.json_encode($value));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Firebase RTDB FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
