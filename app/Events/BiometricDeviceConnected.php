<?php

namespace App\Events;

use App\Models\HRM\BiometricDevice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BiometricDeviceConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BiometricDevice $device
    ) {}
}
