<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Notifications\RfiObjectionNotification;
use App\Traits\ChainageMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ObjectionController extends Controller
{
    use ChainageMatcher;

    /**
     * Get statistics for objections.
     */
    private function getStatistics(): array
    {
        return [
            'total' => RfiObjection::count(),
            'active' => RfiObjection::whereIn('status', ['draft', 'submitted', 'under_review'])->count(),
            'resolved' => RfiObjection::where('status', 'resolved')->count(),
            'rejected' => RfiObjection::where('status', 'rejected')->count(),
            'pending' => RfiObjection::whereIn('status', ['submitted', 'under_review'])->count(),
        ];
    }

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
                'statusLogs.changedBy:id,name',
                'chainages', // Load chainages for proper display
                'media', // Eager load media to prevent N+1 queries
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
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('chainage_from', 'like', "%{$search}%")
                    ->orWhere('chainage_to', 'like', "%{$search}%");
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
            'statistics' => $this->getStatistics(),
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
            'type' => 'nullable|string|in:'.implode(',', RfiObjection::$types),
            // Legacy fields (kept for backward compatibility, will be migrated to chainages table)
            'chainage_from' => 'nullable|string|max:50',
            'chainage_to' => 'nullable|string|max:50',
            // New fields for multiple chainages
            'specific_chainages' => 'nullable|string|max:10000', // Comma-separated: K35+897, K36+987
            'chainage_range_from' => 'nullable|string|max:50', // Range start: K36+580
            'chainage_range_to' => 'nullable|string|max:50',   // Range end: K37+540
            'description' => 'required|string|max:5000',
            'reason' => 'required|string|max:5000',
            'status' => 'nullable|string|in:draft,submitted',
            'rfi_ids' => 'nullable|array',
            'rfi_ids.*' => 'exists:daily_works,id',
            'attachment_notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            // Use legacy fields if new fields not provided (backward compatibility)
            $chainageFrom = $validated['chainage_range_from'] ?? $validated['chainage_from'] ?? null;
            $chainageTo = $validated['chainage_range_to'] ?? $validated['chainage_to'] ?? null;

            $objection = RfiObjection::create([
                'title' => $validated['title'],
                'category' => $validated['category'] ?? RfiObjection::CATEGORY_OTHER,
                'type' => $validated['type'] ?? null,
                'chainage_from' => $chainageFrom,
                'chainage_to' => $chainageTo,
                'description' => $validated['description'],
                'reason' => $validated['reason'],
                'status' => $validated['status'] ?? RfiObjection::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);

            // Sync chainages to the new table
            // Pass the raw chainage strings, not parsed meters
            $specificChainagesStr = $validated['specific_chainages'] ?? '';
            $specificChainagesArray = array_filter(
                array_map('trim', preg_split('/\s*,\s*/', $specificChainagesStr)),
                fn ($ch) => ! empty($ch)
            );
            $objection->syncChainages($specificChainagesArray, $chainageFrom, $chainageTo);

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
            'type' => 'nullable|string|in:'.implode(',', RfiObjection::$types),
            // Legacy fields (kept for backward compatibility)
            'chainage_from' => 'nullable|string|max:50',
            'chainage_to' => 'nullable|string|max:50',
            // New fields for multiple chainages
            'specific_chainages' => 'nullable|string|max:10000', // Comma-separated: K35+897, K36+987
            'chainage_range_from' => 'nullable|string|max:50', // Range start: K36+580
            'chainage_range_to' => 'nullable|string|max:50',   // Range end: K37+540
            'description' => 'sometimes|required|string|max:5000',
            'reason' => 'sometimes|required|string|max:5000',
        ]);

        try {
            // Use new fields if provided, otherwise fall back to legacy
            $chainageFrom = $validated['chainage_range_from'] ?? $validated['chainage_from'] ?? $objection->chainage_from;
            $chainageTo = $validated['chainage_range_to'] ?? $validated['chainage_to'] ?? $objection->chainage_to;

            // Update the model (use legacy fields for backward compatibility)
            $updateData = array_filter([
                'title' => $validated['title'] ?? null,
                'category' => $validated['category'] ?? null,
                'type' => array_key_exists('type', $validated) ? $validated['type'] : null,
                'chainage_from' => $chainageFrom,
                'chainage_to' => $chainageTo,
                'description' => $validated['description'] ?? null,
                'reason' => $validated['reason'] ?? null,
            ], fn ($value, $key) => $value !== null || $key === 'type', ARRAY_FILTER_USE_BOTH);

            $objection->update($updateData);

            // Sync chainages to the new table if any chainage fields were provided
            if (isset($validated['specific_chainages']) || isset($validated['chainage_range_from']) || isset($validated['chainage_from'])) {
                // Pass the raw chainage strings, not parsed meters
                $specificChainagesStr = $validated['specific_chainages'] ?? '';
                $specificChainagesArray = array_filter(
                    array_map('trim', preg_split('/\s*,\s*/', $specificChainagesStr)),
                    fn ($ch) => ! empty($ch)
                );
                $objection->syncChainages($specificChainagesArray, $chainageFrom, $chainageTo);
            }

            return response()->json([
                'message' => 'Objection updated successfully.',
                'objection' => $objection->fresh(['createdBy:id,name', 'dailyWorks:id,number', 'chainages']),
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
     * Suggest RFIs based on chainage matching.
     * Supports:
     * - Multiple specific chainages (comma-separated in chainage_from)
     * - Range chainages (chainage_from to chainage_to)
     * - Search by number/location/description
     */
    public function suggestRfis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chainage_from' => 'nullable|string|max:5000', // Allow longer for multiple chainages
            'chainage_to' => 'nullable|string|max:50',
            'type' => 'nullable|string|in:'.implode(',', RfiObjection::$types),
            'search' => 'nullable|string|max:255',
        ]);

        try {
            $chainageFrom = $validated['chainage_from'] ?? null;
            $chainageTo = $validated['chainage_to'] ?? null;
            $type = $validated['type'] ?? null;
            $search = $validated['search'] ?? null;

            // If search term provided, search by number, location, or description
            if (! empty($search)) {
                $query = DailyWork::query()
                    ->select('id', 'number', 'location', 'description', 'type', 'date', 'side', 'qty_layer', 'incharge', 'status')
                    ->with('inchargeUser:id,name')
                    ->where(function ($q) use ($search) {
                        $q->where('number', 'like', "%{$search}%")
                            ->orWhere('location', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });

                // Filter by type if provided
                if (! empty($type)) {
                    $query->where('type', $type);
                }

                $rfis = $query->orderBy('location')
                    ->orderBy('date', 'desc')
                    ->get();

                // Map to simple array to avoid infinite recursion from media/relation serialization
                $rfisData = $rfis->map(function ($rfi) {
                    return [
                        'id' => $rfi->id,
                        'number' => $rfi->number,
                        'location' => $rfi->location,
                        'description' => $rfi->description,
                        'type' => $rfi->type,
                        'date' => $rfi->date?->format('Y-m-d'),
                        'side' => $rfi->side,
                        'qty_layer' => $rfi->qty_layer,
                        'incharge' => $rfi->incharge,
                        'status' => $rfi->status,
                        'incharge_user' => $rfi->relationLoaded('inchargeUser') && $rfi->inchargeUser
                            ? ['id' => $rfi->inchargeUser->id, 'name' => $rfi->inchargeUser->name]
                            : null,
                    ];
                })->values();

                return response()->json([
                    'rfis' => $rfisData,
                    'count' => $rfisData->count(),
                    'total_found' => $rfisData->count(),
                    'match_type' => 'search',
                ]);
            }

            // Parse chainages
            $specificMeters = [];
            $rangeStart = null;
            $rangeEnd = null;

            // Check if it's multiple specific chainages (comma-separated) or a range
            if (! empty($chainageFrom)) {
                if (! empty($chainageTo)) {
                    // Range mode: chainage_from to chainage_to
                    $rangeStart = $this->parseChainageToMeters($chainageFrom);
                    $rangeEnd = $this->parseChainageToMeters($chainageTo);

                    // Ensure proper order
                    if ($rangeStart !== null && $rangeEnd !== null && $rangeStart > $rangeEnd) {
                        $temp = $rangeStart;
                        $rangeStart = $rangeEnd;
                        $rangeEnd = $temp;
                    }
                } else {
                    // Specific chainages mode (could be comma-separated)
                    $specificMeters = $this->parseMultipleChainages($chainageFrom);
                }
            }

            // If no valid chainages, return empty
            if (empty($specificMeters) && ($rangeStart === null || $rangeEnd === null)) {
                return response()->json([
                    'rfis' => [],
                    'count' => 0,
                    'total_found' => 0,
                    'match_type' => 'none',
                    'message' => 'No valid chainages provided',
                ]);
            }

            // Extract unique kilometer prefixes from the chainages to pre-filter RFIs
            $kmPrefixes = [];
            if (! empty($specificMeters)) {
                foreach ($specificMeters as $meters) {
                    $km = intdiv($meters, 1000);
                    $kmPrefixes["K{$km}+"] = true;
                }
            } elseif ($rangeStart !== null && $rangeEnd !== null) {
                $startKm = intdiv($rangeStart, 1000);
                $endKm = intdiv($rangeEnd, 1000);
                for ($km = $startKm; $km <= $endKm; $km++) {
                    $kmPrefixes["K{$km}+"] = true;
                }
            }

            \Log::debug('suggestRfis: km prefixes', ['count' => count($kmPrefixes), 'prefixes' => array_keys($kmPrefixes)]);

            // Build query to pre-filter RFIs by location prefix
            $query = DailyWork::query()
                ->select('id', 'number', 'location', 'description', 'type', 'date', 'side', 'qty_layer', 'incharge', 'status')
                ->with('inchargeUser:id,name')
                ->whereNotNull('location')
                ->where('location', '!=', '');

            // Filter by type if provided
            if (! empty($type)) {
                $query->where('type', $type);
            }

            // Add location prefix filters if we have any
            if (! empty($kmPrefixes)) {
                $query->where(function ($q) use ($kmPrefixes) {
                    foreach (array_keys($kmPrefixes) as $prefix) {
                        $q->orWhere('location', 'like', $prefix.'%');
                    }
                });
            }

            \Log::debug('suggestRfis: executing query');

            $allRfis = $query->orderBy('location')
                ->orderBy('date', 'desc')
                ->get();

            \Log::debug('suggestRfis: query done', ['count' => $allRfis->count()]);

            // Pre-compute set for O(1) lookups when we have many specific meters
            $metersSet = ! empty($specificMeters) ? array_flip($specificMeters) : null;

            \Log::debug('suggestRfis: starting filter', ['specificCount' => count($specificMeters)]);

            $matchedRfis = $allRfis->filter(function ($rfi) use ($specificMeters, $rangeStart, $rangeEnd, $metersSet) {
                return $this->doesObjectionMatchRfi(
                    $specificMeters,
                    $rangeStart,
                    $rangeEnd,
                    $rfi->location,
                    $metersSet
                );
            })->values();

            \Log::debug('suggestRfis: filter done', ['matched' => $matchedRfis->count()]);

            $matchType = ! empty($specificMeters) ? 'specific' : 'range';

            // Map to simple array to avoid infinite recursion from media/relation serialization
            $rfisData = $matchedRfis->map(function ($rfi) {
                return [
                    'id' => $rfi->id,
                    'number' => $rfi->number,
                    'location' => $rfi->location,
                    'description' => $rfi->description,
                    'type' => $rfi->type,
                    'date' => $rfi->date?->format('Y-m-d'),
                    'side' => $rfi->side,
                    'qty_layer' => $rfi->qty_layer,
                    'incharge' => $rfi->incharge,
                    'status' => $rfi->status,
                    'incharge_user' => $rfi->relationLoaded('inchargeUser') && $rfi->inchargeUser
                        ? ['id' => $rfi->inchargeUser->id, 'name' => $rfi->inchargeUser->name]
                        : null,
                ];
            })->values();

            return response()->json([
                'rfis' => $rfisData,
                'count' => $rfisData->count(),
                'total_found' => $rfisData->count(),
                'match_type' => $matchType,
                'parsed_chainages' => [
                    'specific_count' => count($specificMeters),
                    'range_start' => $rangeStart,
                    'range_end' => $rangeEnd,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to suggest RFIs.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export suggested RFIs for an objection (same logic as suggestRfis but formatted for export).
     */
    public function exportSuggestedRfis(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);

        try {
            $chainageFrom = $request->input('chainage_from');
            $chainageTo = $request->input('chainage_to');
            $type = $request->input('type');
            $search = $request->input('search');
            $objectionId = $request->input('objection_id');

            // Get the current objection if provided
            $objection = null;
            if ($objectionId) {
                $objection = RfiObjection::find($objectionId);
            }

            // Build the query - same logic as suggestRfis
            $query = DailyWork::query()
                ->select('id', 'number', 'location', 'description', 'type', 'date', 'side', 'qty_layer', 'incharge', 'status', 'rfi_submission_date');

            // Filter by type if provided
            if (! empty($type)) {
                $query->where('type', $type);
            }

            // If search is provided, use search-based matching
            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('number', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });

                $rfis = $query->orderBy('location')->orderBy('date', 'desc')->get();
            } else {
                // Use chainage-based matching
                $specificMeters = [];
                $rangeStart = null;
                $rangeEnd = null;

                if (! empty($chainageFrom)) {
                    if (! empty($chainageTo)) {
                        $rangeStart = $this->parseChainageToMeters($chainageFrom);
                        $rangeEnd = $this->parseChainageToMeters($chainageTo);

                        if ($rangeStart !== null && $rangeEnd !== null && $rangeStart > $rangeEnd) {
                            $temp = $rangeStart;
                            $rangeStart = $rangeEnd;
                            $rangeEnd = $temp;
                        }
                    } else {
                        $chainages = preg_split('/[,\s]+/', $chainageFrom, -1, PREG_SPLIT_NO_EMPTY);
                        foreach ($chainages as $ch) {
                            $meters = $this->parseChainageToMeters(trim($ch));
                            if ($meters !== null) {
                                $specificMeters[] = $meters;
                            }
                        }
                    }
                }

                // Kilometer prefix filtering
                $kilometerPrefixes = [];
                if (! empty($specificMeters)) {
                    foreach ($specificMeters as $m) {
                        $kilometerPrefixes[] = 'K'.floor($m / 1000).'+';
                    }
                } elseif ($rangeStart !== null && $rangeEnd !== null) {
                    $startKm = (int) floor($rangeStart / 1000);
                    $endKm = (int) floor($rangeEnd / 1000);
                    for ($km = $startKm; $km <= $endKm; $km++) {
                        $kilometerPrefixes[] = 'K'.$km.'+';
                    }
                }

                if (! empty($kilometerPrefixes)) {
                    $kilometerPrefixes = array_unique($kilometerPrefixes);
                    $query->where(function ($q) use ($kilometerPrefixes) {
                        foreach ($kilometerPrefixes as $prefix) {
                            $q->orWhere('location', 'like', $prefix.'%');
                        }
                    });
                }

                $allRfis = $query->orderBy('location')->orderBy('date', 'desc')->get();
                $metersSet = ! empty($specificMeters) ? array_flip($specificMeters) : null;

                $rfis = $allRfis->filter(function ($rfi) use ($specificMeters, $rangeStart, $rangeEnd, $metersSet) {
                    return $this->doesObjectionMatchRfi($specificMeters, $rangeStart, $rangeEnd, $rfi->location, $metersSet);
                })->values();
            }

            // Format data for export
            $exportData = $rfis->map(function ($rfi) use ($objection) {
                return [
                    'RFI Number' => $rfi->number,
                    'Date' => $rfi->date?->format('Y-m-d') ?? 'N/A',
                    'Chainage' => $rfi->location ?? 'N/A',
                    'Side' => $rfi->side ?? 'N/A',
                    'Layer/Qty' => $rfi->qty_layer ?? 'N/A',
                    'Type' => $rfi->type ?? 'N/A',
                    'Description' => $rfi->description ?? 'N/A',
                    'Status' => $rfi->status ?? 'N/A',
                    'RFI Submission Date' => $rfi->rfi_submission_date?->format('Y-m-d') ?? 'N/A',
                    'Objection Title' => $objection?->title ?? 'N/A',
                    'Objection Chainage' => ($objection?->chainage_from ?? '').' - '.($objection?->chainage_to ?? ''),
                ];
            });

            $filename = 'suggested_rfis';
            if ($objection) {
                $filename .= '_'.str_replace(['/', '\\', ' '], '_', $objection->title);
            }
            $filename .= '_'.now()->format('Y_m_d_H_i_s');

            return response()->json([
                'data' => $exportData,
                'filename' => $filename,
                'total_records' => $exportData->count(),
                'message' => 'Export data prepared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to export suggested RFIs.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @deprecated Use ChainageMatcher trait instead
     * Kept for backward compatibility
     */
    protected function parseChainageToMetersLegacy(?string $chainage): ?float
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

    /**
     * Delete an objection.
     */
    public function destroy(RfiObjection $objection): JsonResponse
    {
        $this->authorize('delete', $objection);

        try {
            $objection->delete();

            return response()->json([
                'message' => 'Objection deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete objection.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit an objection for review.
     */
    public function submit(RfiObjection $objection): JsonResponse
    {
        $this->authorize('submit', $objection);

        try {
            return DB::transaction(function () use ($objection) {
                // Lock the row to prevent race conditions
                $objection = RfiObjection::where('id', $objection->id)->lockForUpdate()->first();

                if ($objection->status !== RfiObjection::STATUS_DRAFT) {
                    return response()->json([
                        'error' => 'Only draft objections can be submitted. Status may have changed.',
                    ], 409);
                }

                $oldStatus = $objection->status;
                $objection->update(['status' => RfiObjection::STATUS_SUBMITTED]);

                // Log status change
                $objection->statusLogs()->create([
                    'from_status' => $oldStatus,
                    'to_status' => RfiObjection::STATUS_SUBMITTED,
                    'notes' => 'Submitted for review',
                    'changed_by' => auth()->id(),
                    'changed_at' => now(),
                ]);

                return response()->json([
                    'message' => 'Objection submitted for review.',
                    'objection' => $objection->fresh(['createdBy', 'resolvedBy', 'dailyWorks', 'statusLogs.changedBy']),
                    'statistics' => $this->getStatistics(),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to submit objection.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start review of an objection.
     */
    public function review(RfiObjection $objection): JsonResponse
    {
        $this->authorize('review', $objection);

        try {
            return DB::transaction(function () use ($objection) {
                // Lock the row to prevent race conditions
                $objection = RfiObjection::where('id', $objection->id)->lockForUpdate()->first();

                if ($objection->status !== RfiObjection::STATUS_SUBMITTED) {
                    return response()->json([
                        'error' => 'Only submitted objections can be put under review. Status may have changed.',
                    ], 409);
                }

                $oldStatus = $objection->status;
                $objection->update(['status' => RfiObjection::STATUS_UNDER_REVIEW]);

                // Log status change
                $objection->statusLogs()->create([
                    'from_status' => $oldStatus,
                    'to_status' => RfiObjection::STATUS_UNDER_REVIEW,
                    'notes' => 'Review started',
                    'changed_by' => auth()->id(),
                    'changed_at' => now(),
                ]);

                return response()->json([
                    'message' => 'Objection is now under review.',
                    'objection' => $objection->fresh(['createdBy', 'resolvedBy', 'dailyWorks', 'statusLogs.changedBy']),
                    'statistics' => $this->getStatistics(),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to start review.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve an objection.
     */
    public function resolve(Request $request, RfiObjection $objection): JsonResponse
    {
        $this->authorize('review', $objection);

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:5000',
        ]);

        try {
            return DB::transaction(function () use ($objection, $validated) {
                // Lock the row to prevent race conditions
                $objection = RfiObjection::where('id', $objection->id)->lockForUpdate()->first();

                if (! in_array($objection->status, [RfiObjection::STATUS_SUBMITTED, RfiObjection::STATUS_UNDER_REVIEW])) {
                    return response()->json([
                        'error' => 'Only submitted or under-review objections can be resolved. Status may have changed.',
                    ], 409);
                }

                $oldStatus = $objection->status;
                $objection->update([
                    'status' => RfiObjection::STATUS_RESOLVED,
                    'resolution_notes' => $validated['resolution_notes'],
                    'resolved_by' => auth()->id(),
                    'resolved_at' => now(),
                ]);

                // Log status change
                $objection->statusLogs()->create([
                    'from_status' => $oldStatus,
                    'to_status' => RfiObjection::STATUS_RESOLVED,
                    'notes' => $validated['resolution_notes'],
                    'changed_by' => auth()->id(),
                    'changed_at' => now(),
                ]);

                // Notify creator and RFI incharges
                $rfiIds = $objection->dailyWorks()->pluck('daily_works.id')->toArray();
                if (! empty($rfiIds)) {
                    $this->notifyRfiIncharges($objection, $rfiIds, 'resolved');
                }

                return response()->json([
                    'message' => 'Objection resolved successfully.',
                    'objection' => $objection->fresh(['createdBy', 'resolvedBy', 'dailyWorks', 'statusLogs.changedBy']),
                    'statistics' => $this->getStatistics(),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to resolve objection.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject an objection.
     */
    public function reject(Request $request, RfiObjection $objection): JsonResponse
    {
        $this->authorize('review', $objection);

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:5000',
        ]);

        try {
            return DB::transaction(function () use ($objection, $validated) {
                // Lock the row to prevent race conditions
                $objection = RfiObjection::where('id', $objection->id)->lockForUpdate()->first();

                if (! in_array($objection->status, [RfiObjection::STATUS_SUBMITTED, RfiObjection::STATUS_UNDER_REVIEW])) {
                    return response()->json([
                        'error' => 'Only submitted or under-review objections can be rejected. Status may have changed.',
                    ], 409);
                }

                $oldStatus = $objection->status;
                $objection->update([
                    'status' => RfiObjection::STATUS_REJECTED,
                    'resolution_notes' => $validated['resolution_notes'],
                    'resolved_by' => auth()->id(),
                    'resolved_at' => now(),
                ]);

                // Log status change
                $objection->statusLogs()->create([
                    'from_status' => $oldStatus,
                    'to_status' => RfiObjection::STATUS_REJECTED,
                    'notes' => $validated['resolution_notes'],
                    'changed_by' => auth()->id(),
                    'changed_at' => now(),
                ]);

                return response()->json([
                    'message' => 'Objection rejected.',
                    'objection' => $objection->fresh(['createdBy', 'resolvedBy', 'dailyWorks', 'statusLogs.changedBy']),
                    'statistics' => $this->getStatistics(),
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to reject objection.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export objections with affected RFI details.
     */
    public function export(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);

        try {
            $query = RfiObjection::query()
                ->with([
                    'createdBy:id,name',
                    'resolvedBy:id,name',
                    'dailyWorks:id,number,location,type,date,rfi_submission_date',
                ]);

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

            // Option to filter only active objections
            if ($request->boolean('only_active')) {
                $query->active();
            }

            $objections = $query->orderBy('created_at', 'desc')->get();

            $exportData = $objections->map(function ($objection) {
                $affectedRfis = $objection->dailyWorks->map(function ($rfi) {
                    $submissionStatus = $rfi->rfi_submission_date
                        ? 'Submitted: '.$rfi->rfi_submission_date->format('Y-m-d')
                        : 'Not Submitted';

                    return "{$rfi->number} @ {$rfi->location} ({$submissionStatus})";
                })->join(' | ');

                return [
                    'Title' => $objection->title,
                    'Category' => RfiObjection::$categoryLabels[$objection->category] ?? $objection->category,
                    'Status' => RfiObjection::$statusLabels[$objection->status] ?? $objection->status,
                    'Chainage From' => $objection->chainage_from ?? 'N/A',
                    'Chainage To' => $objection->chainage_to ?? 'N/A',
                    'Description' => $objection->description,
                    'Reason' => $objection->reason,
                    'Created By' => $objection->createdBy?->name ?? 'N/A',
                    'Created At' => $objection->created_at->format('Y-m-d H:i'),
                    'Resolved By' => $objection->resolvedBy?->name ?? 'N/A',
                    'Resolved At' => $objection->resolved_at?->format('Y-m-d H:i') ?? 'N/A',
                    'Resolution Notes' => $objection->resolution_notes ?? 'N/A',
                    'Affected RFIs Count' => $objection->dailyWorks->count(),
                    'Affected RFIs' => $affectedRfis ?: 'None',
                ];
            });

            return response()->json([
                'data' => $exportData,
                'filename' => 'objections_export_'.now()->format('Y_m_d_H_i_s'),
                'total_records' => $exportData->count(),
                'message' => 'Export data prepared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Export failed.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
