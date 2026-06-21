<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\CompOffLedger;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\RosterDay;
use App\Services\Attendance\CompOffService;
use App\Services\Attendance\OvertimeService;
use App\Services\Attendance\RegularizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceRequestController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RegularizationService $regularization,
        private readonly OvertimeService $overtime,
        private readonly CompOffService $compOff,
    ) {}

    // -------------------------------------------------------------------------
    // Regularization
    // -------------------------------------------------------------------------

    public function storeRegularization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'               => 'required|date',
            'type'               => 'required|in:missing_punchin,missing_punchout,wrong_time,missed_day,other',
            'requested_punchin'  => 'nullable|date',
            'requested_punchout' => 'nullable|date',
            'reason'             => 'required|string|max:500',
        ]);

        $regularization = $this->regularization->request($request->user()->id, $data);

        return $this->successResponse($regularization, 'Regularization request submitted.', 201);
    }

    public function myRegularizations(Request $request): JsonResponse
    {
        $items = AttendanceRegularization::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->successResponse($items);
    }

    // -------------------------------------------------------------------------
    // Overtime
    // -------------------------------------------------------------------------

    public function storeOvertime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'              => 'required|date',
            'requested_minutes' => 'required|integer|min:1|max:1440',
            'reason'            => 'required|string|max:500',
        ]);

        $ot = $this->overtime->request($request->user()->id, $data);

        return $this->successResponse($ot, 'Overtime request submitted.', 201);
    }

    public function myOvertime(Request $request): JsonResponse
    {
        $items = OvertimeRequest::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->successResponse($items);
    }

    // -------------------------------------------------------------------------
    // Comp-off
    // -------------------------------------------------------------------------

    public function myCompOff(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $balance = $this->compOff->balance($userId);

        $entries = CompOffLedger::where('user_id', $userId)
            ->latest()
            ->limit(50)
            ->get();

        return $this->successResponse([
            'balance_minutes' => $balance,
            'entries'         => $entries,
        ]);
    }

    // -------------------------------------------------------------------------
    // My-roster
    // -------------------------------------------------------------------------

    public function myRoster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $rows = RosterDay::with(['shift:id,code,color,name,start_time,end_time,type,crosses_midnight', 'user:id,name'])
            ->where('user_id', $request->user()->id)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get();

        $formatTime = static fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('H:i') : null;

        $days = $rows->keyBy(fn ($row) => $row->date->format('Y-m-d'))
            ->map(fn ($row) => [
                'code'             => $row->shift?->code,
                'name'             => $row->shift?->name,
                'color'            => $row->shift?->color,
                'type'             => $row->shift?->type,
                'start'            => $formatTime($row->shift?->start_time),
                'end'              => $formatTime($row->shift?->end_time),
                'crosses_midnight' => (bool) ($row->shift?->crosses_midnight ?? false),
                'off'              => $row->shift_id === null,
            ]);

        return $this->successResponse([
            'name' => $request->user()->name,
            'days' => $days,
        ]);
    }
}
