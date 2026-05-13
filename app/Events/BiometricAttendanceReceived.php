<?php

namespace App\Events;

use App\Models\HRM\BiometricDevice;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BiometricAttendanceReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BiometricDevice $device,
        public User $user,
        public array $attendanceData
    ) {}
}
