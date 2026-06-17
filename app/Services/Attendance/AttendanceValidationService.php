<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Validator;

class AttendanceValidationService
{
    /**
     * Validate punch attendance data
     */
    public function validatePunch(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'qr_code' => 'nullable|string|max:255',
            'photo' => 'nullable|string|max:10000',
            'check_type' => 'nullable|in:in,out,break_in,break_out,ot_in,ot_out',
        ]);
    }

    /**
     * Validate time correction data
     */
    public function validateTimeCorrection(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'punchin_time' => 'required|date_format:H:i:s',
            'punchout_time' => 'nullable|date_format:H:i:s',
            'reason' => 'required|string|max:500',
        ]);
    }

    /**
     * Validate mark as present data
     */
    public function validateMarkAsPresent(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'user_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'punchin_time' => 'required|date_format:H:i:s',
            'punchout_time' => 'nullable|date_format:H:i:s',
            'reason' => 'nullable|string|max:500',
        ]);
    }

    /**
     * Validate attendance history filters
     */
    public function validateHistoryFilters(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
            'currentMonth' => 'nullable|integer|min:1|max:12',
            'currentYear' => 'nullable|integer|min:2020|max:2100',
            'scope' => 'nullable|in:self,team,all',
            'employee' => 'nullable|string|max:255',
        ]);
    }

    /**
     * Validate monthly summary filters
     */
    public function validateMonthlySummaryFilters(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, [
            'currentMonth' => 'nullable|integer|min:1|max:12',
            'currentYear' => 'nullable|integer|min:2020|max:2100',
            'scope' => 'nullable|in:self,team,all',
        ]);
    }
}
