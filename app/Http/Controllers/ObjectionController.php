<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Notifications\RfiObjectionNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ObjectionController extends Controller
{
    /**
     * Display the objections management page.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RfiObjection::class);

        $query = RfiObjection::query()
            ->with([
                'createdBy:id,name,email',
                'resolvedBy:id,name,email',
                'dailyWorks:id,number,location',
            ])
            ->withCount('dailyWorks');

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('chainage')) {
            $chainage = $request->chainage;
            $query->where(function ($q) use ($chainage) {
                $q->where('chainage_from', '<=', $chainage)
                    ->where('chainage_to', '>=', $chainage);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $objections = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Get filter options
        $creators = User::whereIn('id', function ($query) {
            $query->select('created_by')->from('rfi_objections')->distinct();
        })->select('id', 'name')->get();

        return Inertia::render('Project/Objections/Index', [
            'objections' => $objections,
            'filters' => $request->only(['status', 'category', 'created_by', 'date_from', 'date_to', 'chainage', 'search']),
            'statuses' => RfiObjection::$statusLabels,
            'categories' => RfiObjection::$categoryLabels,
            'creators' => $creators,
        ]);
    }

    /**
     * Store a new objection.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', RfiObjection::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|in:'.implode(',', RfiObjection::$categories),
            'chainage_from' => 'nullable|string|max:50',
            'chainage_to' => 'nullable|string|max:50',
            'description' => 'required|string|max:5000',
            'reason' => 'required|string|max:5000',
            'status' => 'nullable|string|in:draft,submitted',
            'rfi_ids' => 'nullable|array',
            'rfi_ids.*' => 'exists:daily_works,id',
            'attachment_notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $objection = RfiObjection::create([
                'title' => $validated['title'],
                'category' => $validated['category'] ?? RfiObjection::CATEGORY_OTHER,
                'chainage_from' => $validated['chainage_from'] ?? null,
                'chainage_to' => $validated['chainage_to'] ?? null,
                'description' => $validated['description'],
                'reason' => $validated['reason'],
                'status' => $validated['status'] ?? RfiObjection::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);

            // Log initial status
            $objection->statusLogs()->create([
                'from_status' => null,
                'to_status' => $objection->status,
                'notes' => 'Objection created',
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);

            // Attach to RFIs if provided
            if (! empty($validated['rfi_ids'])) {
                $objection->attachToRfis(
                    $validated['rfi_ids'],
                    $validated['attachment_notes'] ?? null
                );

                // Notify RFI incharges
                $this->notifyRfiIncharges($objection, $validated['rfi_ids'], 'attached');
            }

            // Handle file uploads
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $objection->addMedia($file)->toMediaCollection('objection_files');
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Objection created successfully.',
                'objection' => $objection->load(['createdBy:id,name', 'dailyWorks:id,number']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to create objection.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing objection.
     */
    public function update(Request $request, RfiObjection $objection): JsonResponse
    {
        $this->authorize('update', $objection);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|in:'.implode(',', RfiObjection::$categories),
            'chainage_from' => 'nullable|string|max:50',
            'chainage_to' => 'nullable|string|max:50',
            'description' => 'sometimes|required|string|max:5000',
            'reason' => 'sometimes|required|string|max:5000',
        ]);

        try {
            $objection->update($validated);

            return response()->json([
                'message' => 'Objection updated successfully.',
                'objection' => $objection->fresh(['createdBy:id,name', 'dailyWorks:id,number']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update objection.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Attach objection to RFIs.
     */
    public function attachToRfis(Request $request, RfiObjection $objection): JsonResponse
    {
        $this->authorize('update', $objection);

        $validated = $request->validate([
            'rfi_ids' => 'required|array|min:1',
            'rfi_ids.*' => 'exists:daily_works,id',
            'attachment_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $objection->attachToRfis(
                $validated['rfi_ids'],
                $validated['attachment_notes'] ?? null
            );

            // Notify RFI incharges
            $this->notifyRfiIncharges($objection, $validated['rfi_ids'], 'attached');

            return response()->json([
                'message' => 'Objection attached to RFIs successfully.',
                'objection' => $objection->load(['dailyWorks:id,number,location']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to attach objection to RFIs.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detach objection from RFIs.
     */
    public function detachFromRfis(Request $request, RfiObjection $objection): JsonResponse
    {
        $this->authorize('update', $objection);

        $validated = $request->validate([
            'rfi_ids' => 'required|array|min:1',
            'rfi_ids.*' => 'exists:daily_works,id',
        ]);

        try {
            $detachedCount = $objection->detachFromRfis($validated['rfi_ids']);

            return response()->json([
                'message' => "Objection detached from {$detachedCount} RFI(s) successfully.",
                'objection' => $objection->load(['dailyWorks:id,number,location']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to detach objection from RFIs.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Suggest RFIs based on chainage range.
     */
    public function suggestRfis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chainage_from' => 'required|string|max:50',
            'chainage_to' => 'required|string|max:50',
        ]);

        try {
            // Parse chainage values to get KM numbers for comparison
            // Chainage format: K23+500 means KM 23 at 500 meters
            $fromKm = $this->parseChainageToMeters($validated['chainage_from']);
            $toKm = $this->parseChainageToMeters($validated['chainage_to']);

            // Query Daily Works that have location field (chainage)
            $rfis = DailyWork::query()
                ->whereNotNull('location')
                ->where('location', '!=', '')
                ->select('id', 'number', 'location', 'description', 'type')
                ->orderBy('location')
                ->limit(100)
                ->get()
                ->filter(function ($rfi) use ($fromKm, $toKm) {
                    // Parse the RFI location and check if it's in range
                    $rfiKm = $this->parseChainageToMeters($rfi->location);
                    if ($rfiKm === null) {
                        return false;
                    }

                    return $rfiKm >= $fromKm && $rfiKm <= $toKm;
                })
                ->values();

            return response()->json([
                'rfis' => $rfis,
                'count' => $rfis->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to suggest RFIs.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse chainage string to meters for comparison.
     * Formats: K23+500, 23+500, KM23+500, K 23+500, etc.
     */
    protected function parseChainageToMeters(?string $chainage): ?float
    {
        if (empty($chainage)) {
            return null;
        }

        // Remove common prefixes and spaces
        $cleaned = preg_replace('/^(KM|K|km|k)\s*/i', '', trim($chainage));

        // Try to parse format like "23+500" or "23.500" or "23500"
        if (preg_match('/^(\d+)[\+\.\s]?(\d*)$/', $cleaned, $matches)) {
            $km = (int) $matches[1];
            $meters = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;

            // Handle case where meters might be 3 digits (like 500) or more
            // Normalize to proper meters (0-999 per km)
            while ($meters >= 1000) {
                $km++;
                $meters -= 1000;
            }

            return ($km * 1000) + $meters;
        }

        // If just a number, assume it's KM
        if (is_numeric($cleaned)) {
            return (float) $cleaned * 1000;
        }

        return null;
    }

    /**
     * Notify RFI incharges when an objection is attached or resolved.
     */
    protected function notifyRfiIncharges(RfiObjection $objection, array $rfiIds, string $eventType): void
    {
        $rfis = DailyWork::whereIn('id', $rfiIds)->with('inchargeUser:id,name,email')->get();

        foreach ($rfis as $rfi) {
            if ($rfi->inchargeUser) {
                $rfi->inchargeUser->notify(
                    new RfiObjectionNotification($objection, $eventType, $rfi)
                );
            }
        }
    }
}
