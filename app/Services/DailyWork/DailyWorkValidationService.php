<?php

namespace App\Services\DailyWork;

use App\Http\Requests\DailyWork\DailyWorkRules;
use App\Models\DailyWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DailyWorkValidationService
{
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
        return $request->validate(DailyWorkRules::fields($request->all(), false), DailyWorkRules::messages());
    }

    /**
     * Validate update daily work request
     */
    public function validateUpdateRequest(Request $request): array
    {
        return $request->validate(DailyWorkRules::fields($request->all(), true), DailyWorkRules::messages());
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
            '*.2' => 'required|string|in:'.implode(',', DailyWork::$types),
            '*.3' => 'required|string',
            '*.4' => 'required|string|custom_location',
        ];
    }

    /**
     * Get validation messages for import data
     */
    private function getImportValidationMessages(): array
    {
        return [
            '*.0.required' => ':attribute must have a valid date.',
            '*.0.date_format' => ':attribute must be in the format Y-m-d.',
            '*.1.required' => ':attribute must have a value.',
            '*.2.required' => ':attribute must have a value.',
            '*.2.in' => ':attribute must be one of: '.implode(', ', DailyWork::$types).'.',
            '*.3.required' => ':attribute must have a value.',
            '*.4.required' => ':attribute must have a value.',
        ];
    }
}
