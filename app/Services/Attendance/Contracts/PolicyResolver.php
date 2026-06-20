<?php

namespace App\Services\Attendance\Contracts;

use App\Services\Attendance\DTO\PolicyProfile;
use Carbon\CarbonInterface;

interface PolicyResolver
{
    public function resolve(int $userId, CarbonInterface $date): PolicyProfile;
}
