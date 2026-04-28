<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DailyWorkValidationService
{
    private \App\Services\Cache\ReferenceDataCacheService $cacheService;

    public function __construct(\App\Services\Cache\ReferenceDataCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Validate import file request
     */
    public function validateImportFile(Request $request): void
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);
    }

    /**
     * Validate imported daily works data
     */
    public function validateImportedData(array $importedDailyWorks, int $sheetIndex): array
    {
        $index = $sheetIndex + 1;
        $referenceDate = $importedDailyWorks[0][0] ?? null;

        if (! $referenceDate) {
            throw ValidationException::withMessages([
                'date' => "Sheet {$index} is missing a reference date.",
            ]);
        }

        $customAttributes = $this->buildCustomAttributes($importedDailyWorks, $index);
        $rules = $this->getImportValidationRules($referenceDate);
        $messages = $this->getImportValidationMessages();

        $validator = Validator::make($importedDailyWorks, $rules, $messages, $customAttributes);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return [
            'referenceDate' => $referenceDate,
            'validated' => true,
        ];
    }

    /**
     * Validate add daily work request
     */
    public function validateAddRequest(Request $request): array
    {
        $statuses = $this->cacheService->getDailyWorkStatuses();
        $types = $this->cacheService->getDailyWorkTypes();
        $sides = $this->cacheService->getDailyWorkSides();

        $validatedData = $request->validate([
            'date' => 'required|date',
            'number' => 'required|string',
            'planned_time' => 'required|string|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/',
            'status' => 'required|in:'.implode(',', $statuses),
            'inspection_result' => $this->getInspectionResultValidation($request),
            'type' => 'required|in:'.implode(',', $types),
            'description' => 'required|string|min:10|max:500',
            'location' => 'required|string|enhanced_location',
            'side' => 'required|in:'.implode(',', $sides),
            'qty_layer' => $this->getQtyLayerValidation($request),
            'completion_time' => $this->getCompletionTimeValidation($request),
            'inspection_details' => 'nullable|string|max:1000',
        ], $this->getValidationMessages());

        // Perform cross-field validation
        $this->validateCrossFieldRules($validatedData);

        return $validatedData;
    }

    /**
     * Validate update daily work request
     */
    public function validateUpdateRequest(Request $request): array
    {
        $statuses = $this->cacheService->getDailyWorkStatuses();
        $types = $this->cacheService->getDailyWorkTypes();
        $sides = $this->cacheService->getDailyWorkSides();

        $validatedData = $request->validate([
            'id' => 'required|integer|exists:daily_works,id',
            'date' => 'required|date',
            'number' => 'required|string',
            'planned_time' => 'required|string|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/',
            'status' => 'required|in:'.implode(',', $statuses),
            'inspection_result' => $this->getInspectionResultValidation($request),
            'type' => 'required|in:'.implode(',', $types),
            'description' => 'required|string|min:10|max:500',
            'location' => 'required|string|enhanced_location',
            'side' => 'required|in:'.implode(',', $sides),
            'qty_layer' => $this->getQtyLayerValidation($request),
            'completion_time' => $this->getCompletionTimeValidation($request),
            'inspection_details' => 'nullable|string|max:1000',
        ], array_merge($this->getValidationMessages(), [
            'id.required' => 'Daily Work ID is required.',
            'id.integer' => 'Daily Work ID must be an integer.',
            'id.exists' => 'Daily Work not found.',
        ]));

        // Perform cross-field validation
        $this->validateCrossFieldRules($validatedData);

        return $validatedData;
    }

    /**
     * Build custom attributes for validation messages
     */
    private function buildCustomAttributes(array $importedDailyWorks, int $sheetIndex): array
    {
        $customAttributes = [];

        foreach ($importedDailyWorks as $rowIndex => $importedDailyWork) {
            $taskNumber = $importedDailyWork[1] ?? 'unknown';
            $date = $importedDailyWork[0] ?? 'unknown';

            $customAttributes["$rowIndex.0"] = "Sheet {$sheetIndex} - Daily Work number {$taskNumber}'s date {$date}";
            $customAttributes["$rowIndex.1"] = "Sheet {$sheetIndex} - Daily Work number {$taskNumber}'s RFI number";
            $customAttributes["$rowIndex.2"] = "Sheet {$sheetIndex} - Daily Work number {$taskNumber}'s type";
            $customAttributes["$rowIndex.3"] = "Sheet {$sheetIndex} - Daily Work number {$taskNumber}'s description";
            $customAttributes["$rowIndex.4"] = "Sheet {$sheetIndex} - Daily Work number {$taskNumber}'s location";
        }

        return $customAttributes;
    }

    /**
     * Get validation rules for import data
     */
    private function getImportValidationRules(string $referenceDate): array
    {
        $types = $this->cacheService->getDailyWorkTypes();

        return [
            '*.0' => [
                'required',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) use ($referenceDate) {
                    if ($value !== $referenceDate) {
                        $fail("The $attribute must match the reference date {$referenceDate}.");
                    }
                },
            ],
            '*.1' => 'required|string',
            '*.2' => 'required|string|in:'.implode(',', $types),
            '*.3' => 'required|string|min:10|max:500',
            '*.4' => 'required|string|enhanced_location',
        ];
    }

    /**
     * Get validation messages for import data
     */
    private function getImportValidationMessages(): array
    {
        $types = $this->cacheService->getDailyWorkTypes();

        return [
            '*.0.required' => ':attribute must have a valid date.',
            '*.0.date_format' => ':attribute must be in the format Y-m-d.',
            '*.1.required' => ':attribute must have a value.',
            '*.2.required' => ':attribute must have a value.',
            '*.2.in' => ':attribute must be one of: '.implode(', ', $types).'.',
            '*.3.required' => ':attribute must have a value.',
            '*.3.min' => ':attribute must be at least 10 characters.',
            '*.3.max' => ':attribute must not exceed 500 characters.',
            '*.4.required' => ':attribute must have a value.',
            '*.4.enhanced_location' => ':attribute must be a valid chainage format within jurisdiction.',
        ];
    }

    /**
     * Get inspection result validation rule
     */
    private function getInspectionResultValidation(Request $request): string
    {
        $status = $request->input('status');
        $baseRule = 'nullable|in:'.implode(',', DailyWork::$inspectionResults);

        if ($status === DailyWork::STATUS_COMPLETED) {
            return 'required|in:'.implode(',', DailyWork::$inspectionResults);
        }

        return $baseRule;
    }

    /**
     * Get qty_layer validation rule based on work type
     */
    private function getQtyLayerValidation(Request $request): string
    {
        $type = $request->input('type');

        if ($type === DailyWork::TYPE_EMBANKMENT) {
            return 'required|string|regex:/^[0-9]+(\.[0-9]+)?$/';
        }

        if ($type === DailyWork::TYPE_PAVEMENT) {
            return 'nullable|string|regex:/^[0-9]+(\.[0-9]+)?$/';
        }

        return 'nullable|string';
    }

    /**
     * Get completion time validation rule
     */
    private function getCompletionTimeValidation(Request $request): string
    {
        if ($request->input('status') === DailyWork::STATUS_COMPLETED) {
            return 'required|string|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/|after:planned_time';
        }

        return 'nullable|string|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/';
    }

    /**
     * Get enhanced validation messages
     */
    private function getValidationMessages(): array
    {
        $statuses = $this->cacheService->getDailyWorkStatuses();
        $inspectionResults = $this->cacheService->getInspectionResults();
        $types = $this->cacheService->getDailyWorkTypes();
        $sides = $this->cacheService->getDailyWorkSides();

        return [
            'date.required' => 'RFI Date is required.',
            'number.required' => 'RFI Number is required.',
            'planned_time.required' => 'RFI Time is required.',
            'planned_time.regex' => 'RFI Time must be in HH:MM format (24-hour).',
            'planned_time.string' => 'RFI Time must be a string.',
            'status.required' => 'Status is required.',
            'status.in' => 'Status must be one of: '.implode(', ', $statuses).'.',
            'inspection_result.required' => 'Inspection result is required for completed work.',
            'inspection_result.in' => 'Inspection result must be one of: '.implode(', ', $inspectionResults).'.',
            'type.required' => 'Type is required.',
            'type.in' => 'Type must be one of: '.implode(', ', $types).'.',
            'description.required' => 'Description is required.',
            'description.min' => 'Description must be at least 10 characters.',
            'description.max' => 'Description cannot exceed 500 characters.',
            'location.required' => 'Location is required.',
            'location.enhanced_location' => 'Location must be a valid chainage format (e.g., K14+500, K30-K35) within valid jurisdiction.',
            'side.required' => 'Road Type is required.',
            'side.in' => 'Road Type must be one of: '.implode(', ', $sides).'.',
            'qty_layer.required' => 'Layer No. is required for embankment work.',
            'qty_layer.regex' => 'Layer No. must be a valid number.',
            'completion_time.required' => 'Completion time is required when status is completed.',
            'completion_time.regex' => 'Completion time must be in HH:MM format (24-hour).',
            'completion_time.after' => 'Completion time must be after planned time.',
            'inspection_details.max' => 'Inspection details cannot exceed 1000 characters.',
        ];
    }

    /**
     * Perform cross-field validation rules
     */
    private function validateCrossFieldRules(array $data): void
    {
        $errors = [];

        // Validate embankment work requirements
        if (($data['type'] ?? null) === DailyWork::TYPE_EMBANKMENT) {
            if (empty($data['qty_layer'])) {
                $errors['qty_layer'] = 'Layer number is required for embankment work.';
            }

            // Check if side is appropriate for embankment work
            if (!in_array($data['side'] ?? null, ['TR-R', 'TR-L', 'Both'])) {
                $errors['side'] = 'Embankment work must be on TR-R, TR-L, or Both sides.';
            }
        }

        // Validate pavement work requirements
        if (($data['type'] ?? null) === DailyWork::TYPE_PAVEMENT) {
            // Pavement work typically requires specific chainage ranges
            if (!preg_match('/K\d+\+/', $data['location'] ?? '')) {
                $errors['location'] = 'Pavement work location should include specific chainage (e.g., K14+500).';
            }
        }

        // Validate structure work requirements
        if (($data['type'] ?? null) === DailyWork::TYPE_STRUCTURE) {
            // Structure work should have specific location patterns
            if (!preg_match('/K\d+/', $data['location'] ?? '')) {
                $errors['location'] = 'Structure work location should specify kilometer (e.g., K25).';
            }
        }

        // Validate status-specific requirements
        if (($data['status'] ?? null) === DailyWork::STATUS_COMPLETED) {
            if (empty($data['completion_time'])) {
                $errors['completion_time'] = 'Completion time is required for completed work.';
            }

            if (empty($data['inspection_result'])) {
                $errors['inspection_result'] = 'Inspection result is required for completed work.';
            }
        }

        // Validate time logic
        if (!empty($data['planned_time']) && !empty($data['completion_time'])) {
            $plannedTime = strtotime($data['planned_time']);
            $completionTime = strtotime($data['completion_time']);

            if ($completionTime < $plannedTime) {
                $errors['completion_time'] = 'Completion time cannot be before planned time.';
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Get import validation rules for update operations
     */
    private function getImportValidationRulesForUpdate(): array
    {
        return [
            '*.0' => [
                'required',
                'date_format:Y-m-d',
            ],
            '*.1' => 'required|string',
            '*.2' => 'required|string|in:'.implode(',', DailyWork::$types),
            '*.3' => 'required|string|min:10|max:500',
            '*.4' => 'required|string|enhanced_location',
        ];
    }

    /**
     * Get import validation messages for update operations
     */
    private function getImportValidationMessagesForUpdate(): array
    {
        return [
            '*.0.required' => ':attribute must have a valid date.',
            '*.0.date_format' => ':attribute must be in the format Y-m-d.',
            '*.1.required' => ':attribute must have a value.',
            '*.2.required' => ':attribute must have a value.',
            '*.2.in' => ':attribute must be one of: '.implode(', ', DailyWork::$types).'.',
            '*.3.required' => ':attribute must have a value.',
            '*.3.min' => ':attribute must be at least 10 characters.',
            '*.3.max' => ':attribute must not exceed 500 characters.',
            '*.4.required' => ':attribute must have a value.',
            '*.4.enhanced_location' => ':attribute must be a valid chainage format within jurisdiction.',
        ];
    }
}
