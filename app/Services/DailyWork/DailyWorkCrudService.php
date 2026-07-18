<?php

namespace App\Services\DailyWork;

use App\Events\Domain\DailyWorkStatusChanged;
use App\Models\DailyWork;
use App\Traits\JurisdictionMatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyWorkCrudService
{
    use JurisdictionMatcher;

    private DailyWorkValidationService $validationService;

    public function __construct(DailyWorkValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Create a new daily work entry
     */
    public function create(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $validatedData = $this->validationService->validateAddRequest($request);

            // Check if daily work with same number already exists
            $existingDailyWork = DailyWork::where('number', $validatedData['number'])->first();
            if ($existingDailyWork) {
                throw ValidationException::withMessages([
                    'number' => 'A daily work with the same RFI number already exists.',
                ]);
            }

            // Find jurisdiction for the location
            $jurisdiction = $this->findJurisdictionForLocation($validatedData['location']);
            if (! $jurisdiction) {
                throw ValidationException::withMessages([
                    'location' => 'No jurisdiction found for the specified location.',
                ]);
            }

            $inCharge = $jurisdiction->incharge;

            // Create new daily work
            $dailyWork = new DailyWork($validatedData);
            $dailyWork->incharge = $inCharge;
            $dailyWork->status = DailyWork::STATUS_NEW;
            $dailyWork->save();

            return [
                'message' => 'Daily work added successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ];
        });
    }

    /**
     * Update an existing daily work entry
     */
    public function update(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $validatedData = $this->validationService->validateUpdateRequest($request);

            $dailyWork = DailyWork::findOrFail($validatedData['id']);

            // Check if another daily work with same number exists (excluding current)
            $existingDailyWork = DailyWork::where('number', $validatedData['number'])
                ->where('id', '!=', $validatedData['id'])
                ->first();

            if ($existingDailyWork) {
                throw ValidationException::withMessages([
                    'number' => 'A daily work with the same RFI number already exists.',
                ]);
            }

            // Find jurisdiction for the location if location changed
            if ($dailyWork->location !== $validatedData['location']) {
                $jurisdiction = $this->findJurisdictionForLocation($validatedData['location']);
                if (! $jurisdiction) {
                    throw ValidationException::withMessages([
                        'location' => 'No jurisdiction found for the specified location.',
                    ]);
                }
                $validatedData['incharge'] = $jurisdiction->incharge;
            }

            // Capture the pre-update status so we only announce a REAL transition.
            $previousStatus = $dailyWork->status;

            // Update daily work
            $dailyWork->update($validatedData);

            // Domain bus (additive, after-commit). Dispatched inside this
            // DB::transaction: held until commit, discarded on rollback. Only a
            // genuine status change is a transition — an edit that leaves the
            // status untouched emits nothing.
            if (array_key_exists('status', $validatedData) && $validatedData['status'] !== $previousStatus) {
                DailyWorkStatusChanged::dispatch(
                    $request->user()?->id,
                    $dailyWork->id,
                    $previousStatus,
                    (string) $validatedData['status'],
                );
            }

            return [
                'message' => 'Daily work updated successfully',
                'dailyWork' => $dailyWork->fresh(['inchargeUser', 'assignedUser']),
            ];
        });
    }

    /**
     * Delete a daily work entry
     */
    public function delete(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $request->validate([
                'id' => 'required|integer|exists:daily_works,id',
            ]);

            $dailyWork = DailyWork::findOrFail($request->id);

            // Store daily work info for response
            $dailyWorkInfo = [
                'id' => $dailyWork->id,
                'number' => $dailyWork->number,
                'description' => $dailyWork->description,
            ];

            // Delete the daily work (soft delete)
            $dailyWork->delete();

            return [
                'message' => "Daily work '{$dailyWorkInfo['number']}' deleted successfully",
                'deletedDailyWork' => $dailyWorkInfo,
            ];
        });
    }

    /**
     * Get latest timestamp for synchronization
     */
    public function getLatestTimestamp(): string
    {
        return DailyWork::max('updated_at') ?? Carbon::now()->toISOString();
    }

    /**
     * Get ordinal number (1st, 2nd, 3rd, etc.)
     */
    public function getOrdinalNumber(int $number): string
    {
        $suffix = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if ($number % 100 >= 11 && $number % 100 <= 19) {
            return $number.'th';
        }

        $lastDigit = $number % 10;

        return $number.$suffix[$lastDigit];
    }
}
