<?php

namespace App\Services\Operations;

use App\Models\OmEquipment;
use App\Models\OmIncident;
use App\Models\OmLaneClosurePermit;
use App\Models\OmShiftLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OmShiftService
{
    /**
     * Get paginated shift handover logs.
     */
    public function getShiftLogs(int $perPage = 15): LengthAwarePaginator
    {
        return OmShiftLog::with(['operator', 'incomingOperator', 'acknowledgedBy'])
            ->latest('shift_date')
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * Get dynamic active checklist metrics for current shift handover.
     */
    public function getCurrentShiftMetrics(): array
    {
        $openIncidents = OmIncident::whereIn('status', ['detected', 'dispatched', 'on_scene'])->count();
        $activeLaneClosures = OmLaneClosurePermit::where('status', 'active')->count();
        $cctvOffline = OmEquipment::where('category', 'cctv')->where('status', '!=', 'online')->count();
        $vmsOffline = OmEquipment::where('category', 'vms')->where('status', '!=', 'online')->count();
        $wimOffline = OmEquipment::where('category', 'wim')->where('status', '!=', 'online')->count();

        return [
            'open_incidents_count' => $openIncidents,
            'active_lane_closures_count' => $activeLaneClosures,
            'cctv_offline_count' => $cctvOffline,
            'vms_offline_count' => $vmsOffline,
            'wim_offline_count' => $wimOffline,
        ];
    }

    /**
     * Create Shift Handover log.
     */
    public function createShiftLog(array $data, string $operatorId): OmShiftLog
    {
        $metrics = $this->getCurrentShiftMetrics();
        $shiftCode = 'SHF-'.date('Ymd').'-'.strtoupper(substr($data['shift_type'] ?? 'M', 0, 1)).rand(10, 99);

        return OmShiftLog::create([
            'shift_code' => $shiftCode,
            'shift_date' => $data['shift_date'] ?? now()->toDateString(),
            'shift_type' => $data['shift_type'] ?? 'morning',
            'operator_id' => $operatorId,
            'incoming_operator_id' => $data['incoming_operator_id'] ?? null,
            'open_incidents_count' => $metrics['open_incidents_count'],
            'active_lane_closures_count' => $metrics['active_lane_closures_count'],
            'weather_condition' => $data['weather_condition'] ?? 'clear',
            'cctv_offline_count' => $metrics['cctv_offline_count'],
            'vms_offline_count' => $metrics['vms_offline_count'],
            'wim_offline_count' => $metrics['wim_offline_count'],
            'handover_notes' => $data['handover_notes'] ?? null,
            'equipment_exceptions' => $data['equipment_exceptions'] ?? null,
            'is_acknowledged' => false,
        ]);
    }

    /**
     * Dual Sign-off / Acknowledge Shift Handover by Incoming Operator.
     */
    public function acknowledgeShiftLog(OmShiftLog $shiftLog, string $incomingUserId, int $expectedVersion): OmShiftLog
    {
        return DB::transaction(function () use ($shiftLog, $incomingUserId, $expectedVersion) {
            $locked = OmShiftLog::query()->lockForUpdate()->findOrFail($shiftLog->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);

            if ($locked->is_acknowledged) {
                throw ValidationException::withMessages([
                    'handover' => 'This shift handover has already been acknowledged.',
                ]);
            }

            if ((string) $locked->operator_id === $incomingUserId) {
                throw ValidationException::withMessages([
                    'handover' => 'The outgoing operator cannot acknowledge their own handover.',
                ]);
            }

            if ($locked->incoming_operator_id !== null
                && (string) $locked->incoming_operator_id !== $incomingUserId) {
                throw ValidationException::withMessages([
                    'handover' => 'Only the designated incoming operator can acknowledge this handover.',
                ]);
            }

            $locked->update([
                'is_acknowledged' => true,
                'acknowledged_by_user_id' => $incomingUserId,
                'acknowledged_at' => now(),
                'lock_version' => OmVersionGuard::next($locked),
            ]);

            return $locked->fresh();
        });
    }
}
