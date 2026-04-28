<?php

namespace App\Services\Leave;

use App\Models\HRM\Leave;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class LeaveAuditService
{
    /**
     * Log leave creation
     */
    public function logLeaveCreation(Leave $leave, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'leave_created',
            'entity_type' => 'leave',
            'entity_id' => $leave->id,
            'new_values' => [
                'user_id' => $leave->user_id,
                'leave_type_id' => $leave->leave_type_id,
                'from_date' => $leave->from_date,
                'to_date' => $leave->to_date,
                'days' => $leave->days,
            ],
            'description' => "Leave application created for user {$leave->user_id} from {$leave->from_date} to {$leave->to_date}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log leave update
     */
    public function logLeaveUpdate(Leave $leave, array $oldValues, array $newValues, array $data = []): void
    {
        $changedFields = array_keys(array_diff_assoc($oldValues, $newValues));
        
        $description = "Leave application updated for user {$leave->user_id}";
        if (!empty($changedFields)) {
            $fields = implode(', ', $changedFields);
            $description .= " (changed: {$fields})";
        }

        $this->log([
            'user_id' => Auth::id(),
            'action' => 'leave_updated',
            'entity_type' => 'leave',
            'entity_id' => $leave->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log leave deletion
     */
    public function logLeaveDeletion(Leave $leave, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'leave_deleted',
            'entity_type' => 'leave',
            'entity_id' => $leave->id,
            'old_values' => [
                'user_id' => $leave->user_id,
                'leave_type_id' => $leave->leave_type_id,
                'from_date' => $leave->from_date,
                'to_date' => $leave->to_date,
                'status' => $leave->status,
            ],
            'description' => "Leave application deleted for user {$leave->user_id}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log leave approval
     */
    public function logLeaveApproval(Leave $leave, string $reason = '', array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'leave_approved',
            'entity_type' => 'leave',
            'entity_id' => $leave->id,
            'new_values' => [
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ],
            'description' => "Leave application approved for user {$leave->user_id}" . ($reason ? " (reason: {$reason})" : ''),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log leave rejection
     */
    public function logLeaveRejection(Leave $leave, string $reason, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'leave_rejected',
            'entity_type' => 'leave',
            'entity_id' => $leave->id,
            'new_values' => [
                'status' => 'rejected',
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ],
            'description' => "Leave application rejected for user {$leave->user_id} (reason: {$reason})",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log leave balance change
     */
    public function logLeaveBalanceChange(User $user, float $oldBalance, float $newBalance, string $leaveType, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'leave_balance_changed',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'old_values' => [
                'leave_type' => $leaveType,
                'balance' => $oldBalance,
            ],
            'new_values' => [
                'leave_type' => $leaveType,
                'balance' => $newBalance,
            ],
            'description' => "Leave balance changed for user {$user->id}, type: {$leaveType}, from {$oldBalance} to {$newBalance}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log bulk leave operation
     */
    public function logBulkOperation(string $action, array $details, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => "bulk_{$action}",
            'entity_type' => 'leave',
            'description' => "Bulk leave operation: {$action}",
            'total_records' => $details['total_records'] ?? 0,
            'successful_records' => $details['successful_records'] ?? 0,
            'failed_records' => $details['failed_records'] ?? 0,
            'operation_details' => $details['operation_details'] ?? [],
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Generic log method
     */
    protected function log(array $data): void
    {
        \Log::info('Leave Audit Log', $data);
    }
}
