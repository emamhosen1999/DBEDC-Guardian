<?php

namespace App\Services\Attendance;

use App\Models\HRM\CompOffLedger;

class CompOffService
{
    public function credit(int $userId, int $minutes, string $sourceType, ?int $sourceId = null, ?string $note = null): CompOffLedger
    {
        return CompOffLedger::create([
            'user_id' => $userId, 'minutes' => abs($minutes), 'source_type' => $sourceType,
            'source_id' => $sourceId, 'note' => $note,
        ]);
    }

    public function debit(int $userId, int $minutes, ?string $note = null): CompOffLedger
    {
        return CompOffLedger::create([
            'user_id' => $userId, 'minutes' => -abs($minutes), 'source_type' => 'used', 'note' => $note,
        ]);
    }

    public function balance(int $userId): int
    {
        return (int) CompOffLedger::where('user_id', $userId)->sum('minutes');
    }
}
