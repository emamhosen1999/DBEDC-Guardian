<?php

namespace App\Services\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;

class OvertimeService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly CompOffService $compOff,
    ) {}

    public function request(int $userId, array $data): OvertimeRequest
    {
        $ot = OvertimeRequest::create([
            'user_id' => $userId, 'date' => $data['date'],
            'requested_minutes' => (int) $data['requested_minutes'], 'reason' => $data['reason'],
        ]);
        $this->approvals->submit($ot);

        return $ot->refresh();
    }

    public function approve(OvertimeRequest $ot, User $approver, ?string $comments, bool $grantCompOff): array
    {
        $res = $this->approvals->approve($ot, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $ot->refresh();
            if ($grantCompOff && ! $ot->comp_off_granted) {
                $this->compOff->credit($ot->user_id, $ot->requested_minutes, 'overtime', $ot->id, 'OT #'.$ot->id);
                $ot->update(['comp_off_granted' => true]);
            }
        }

        return $res;
    }
}
