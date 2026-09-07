<?php

namespace App\Http\Controllers;

use App\Models\HRM\Holiday;
use App\Services\Holiday\HolidayAuditService;
use App\Services\Holiday\HolidayImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function __construct(private HolidayAuditService $audit) {}

    public function index(): Response
    {
        $holidays = Cache::remember('active_holidays_list', now()->addDay(), function () {
            return Holiday::active()
                ->orderBy('from_date', 'asc')
                ->get();
        });

        // Get statistics for the dashboard
        $stats = Cache::remember('holiday_stats', now()->addDay(), function () {
            $currentYearHolidays = Holiday::active()->currentYear()->get();

            return [
                'total_holidays' => Holiday::active()->count(),
                'upcoming_holidays' => Holiday::active()->upcoming()->count(),
                'current_year_holidays' => $currentYearHolidays->count(),
                'total_holiday_days' => $currentYearHolidays->sum(function ($holiday) {
                    return $holiday->duration;
                }),
            ];
        });

        return Inertia::render('Holidays', [
            'title' => 'Company Holidays',
            'holidays' => $holidays,
            'stats' => $stats,
        ]);
    }

    public function listJson(Request $request): JsonResponse
    {
        $query = Holiday::query()->orderBy('from_date');

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }
        if ($request->filled('year')) {
            $query->whereYear('from_date', (int) $request->input('year'));
        }
        if ($request->filled('type')) {
            $query->byType((string) $request->input('type'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return response()->json(['holidays' => $query->get()]);
    }

    public function stats(): JsonResponse
    {
        $stats = Cache::remember('holiday_stats', now()->addDay(), function (): array {
            $currentYearHolidays = Holiday::active()->currentYear()->get();

            return [
                'total_holidays' => Holiday::active()->count(),
                'upcoming_holidays' => Holiday::active()->upcoming()->count(),
                'current_year_holidays' => $currentYearHolidays->count(),
                'total_holiday_days' => $currentYearHolidays->sum(fn (Holiday $holiday) => $holiday->duration),
            ];
        });

        return response()->json(['stats' => $stats]);
    }

    public function show(Holiday $holiday): JsonResponse
    {
        return response()->json(['holiday' => $holiday]);
    }

    public function create(Request $request)
    {
        // Legacy clients submit edits to the create URL with an id. Route-level
        // create permission must never authorize that update.
        abort_unless($request->user()?->can($request->filled('id') ? 'holidays.update' : 'holidays.create'), 403);

        if ($request->filled('from_date') && ! $request->filled('fromDate')) {
            $request->merge(['fromDate' => $request->input('from_date')]);
        }
        if ($request->filled('to_date') && ! $request->filled('toDate')) {
            $request->merge(['toDate' => $request->input('to_date')]);
        }

        // Validate incoming request
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|exists:holidays,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'fromDate' => 'required|date',
            'toDate' => 'required|date|after_or_equal:fromDate',
            'type' => 'required|string|in:public,religious,national,company,optional',
            'is_recurring' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'recurrence_pattern' => 'nullable|in:none,annual_fixed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $fromDate = $request->input('fromDate');
            $toDate = $request->input('toDate');
            $holidayId = $request->input('id');

            // Check for overlapping holidays (exclude current id on update)
            $overlapQuery = Holiday::where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('from_date', [$fromDate, $toDate])
                    ->orWhereBetween('to_date', [$fromDate, $toDate])
                    ->orWhere(function ($q) use ($fromDate, $toDate) {
                        $q->where('from_date', '<=', $fromDate)
                            ->where('to_date', '>=', $toDate);
                    });
            });
            if ($holidayId) {
                $overlapQuery->where('id', '!=', $holidayId);
            }
            if ($overlapQuery->exists()) {
                return response()->json(['errors' => ['fromDate' => ['Date range overlaps with an existing holiday.']]], 422);
            }

            $data = [
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'type' => $request->input('type', 'company'),
                'is_recurring' => $request->boolean('is_recurring', false),
                'is_active' => $request->boolean('is_active', true),
                'recurrence_pattern' => $request->boolean('is_recurring', false) ? 'annual_fixed' : null,
                'updated_by' => Auth::id(),
            ];

            if ($holidayId) {
                // Update existing holiday record
                $holiday = Holiday::findOrFail($holidayId);
                $before = $holiday->toArray();

                $holiday->update($data);

                $this->audit->record('update', $holiday->id, $before, $holiday->fresh()->toArray());

                $message = 'Holiday updated successfully';
            } else {
                // Create new holiday record
                $data['created_by'] = Auth::id();
                $holiday = Holiday::create($data);

                $this->audit->record('create', $holiday->id, null, $holiday->fresh()->toArray());

                $message = 'Holiday added successfully';
            }

            // Get updated holidays for return
            $holidays = Holiday::active()
                ->orderBy('from_date', 'asc')
                ->get();

            return response()->json([
                'message' => $message,
                'holidays' => $holidays,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to add holiday. Please try again later.',
            ], 500);
        }
    }

    public function update(Request $request, Holiday $holiday)
    {
        $request->merge(['id' => $holiday->getKey()]);

        return $this->create($request);
    }

    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        $request->merge(['id' => $holiday->getKey()]);

        return $this->delete($request);
    }

    public function copyYear(Request $request, HolidayImportService $import): JsonResponse
    {
        $validated = $request->validate([
            'fromYear' => 'required|integer|between:2000,2100',
            'toYear' => 'required|integer|between:2000,2100|different:fromYear',
        ]);

        $created = $import->copyYear((int) $validated['fromYear'], (int) $validated['toYear']);

        $this->audit->record('copy_year', null, null, [
            'from' => (int) $validated['fromYear'],
            'to' => (int) $validated['toYear'],
            'created' => $created,
        ]);

        return response()->json([
            'message' => "{$created} holiday(s) copied from {$validated['fromYear']} to {$validated['toYear']}.",
            'created' => $created,
            'holidays' => Holiday::active()->orderBy('from_date', 'asc')->get(),
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|integer']);

        $holiday = Holiday::onlyTrashed()->findOrFail($request->input('id'));
        $holiday->restore();

        $this->audit->record('restore', $holiday->id, null, $holiday->fresh()->toArray());

        return response()->json([
            'message' => 'Holiday restored successfully',
            'holidays' => Holiday::active()->orderBy('from_date', 'asc')->get(),
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        try {
            // Validate the incoming request
            $request->validate([
                'id' => 'required|exists:holidays,id',
            ]);

            // Find the daily work by ID
            $holiday = Holiday::find($request->input('id'));

            if (! $holiday) {
                return response()->json(['error' => 'Holiday not found'], 404);
            }

            // Delete the holiday
            $before = $holiday->toArray();
            $holiday->delete();

            $this->audit->record('delete', $holiday->id, $before, null);

            // Get updated holidays for return
            $holidays = Holiday::active()
                ->orderBy('from_date', 'asc')
                ->get();

            // Return a success response
            return response()->json([
                'message' => 'Holiday deleted successfully',
                'holidays' => $holidays,
            ], 200);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Catch any exceptions and return an error response
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
