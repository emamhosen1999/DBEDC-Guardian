<?php

namespace App\Http\Controllers;

use App\Services\Operations\OmLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OmLookupController extends Controller
{
    public function __construct(
        protected OmLookupService $lookupService
    ) {}

    /**
     * Public endpoint to get active lookups for dropdowns (Web & Mobile)
     */
    public function activeLookups(): JsonResponse
    {
        $lookups = $this->lookupService->getGroupedLookups();

        return response()->json([
            'success' => true,
            'data' => $lookups,
            'lookups' => $lookups,
        ]);
    }

    /**
     * Admin UI for managing O&M Lookups & Categories
     */
    public function index(Request $request): Response|JsonResponse
    {
        $type = $request->get('type', 'all');
        $search = $request->get('search');
        $lookups = $this->lookupService->getLookupsForAdmin($type, $search);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'lookups' => $lookups,
            ]);
        }

        return Inertia::render('Operations/OmLookupsManager', [
            'lookups' => $lookups,
            'filters' => [
                'type' => $type,
                'search' => $search,
            ],
            'lookupTypes' => [
                ['value' => 'all', 'label' => 'All Categories'],
                ['value' => 'defect_category', 'label' => 'Defect & Distress Categories'],
                ['value' => 'severity', 'label' => 'Severity Levels & SLA'],
                ['value' => 'carriageway_location', 'label' => 'Carriageway & Section Locations'],
                ['value' => 'responsible_party', 'label' => 'Responsible Parties & Contractors'],
                ['value' => 'work_order_category', 'label' => 'Work Order Activity Categories'],
            ],
        ]);
    }

    /**
     * Store new lookup
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:64',
            'key' => 'required|string|max:64',
            'label' => 'required|string|max:128',
            'sla_hours' => 'nullable|integer|min:1',
            'badge_color' => 'nullable|string|max:32',
            'description' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $lookup = $this->lookupService->saveLookup($validated, $request->user()?->id);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'lookup' => $lookup]);
        }

        return back()->with('success', "Lookup '{$lookup->label}' created successfully.");
    }

    /**
     * Update lookup
     */
    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:64',
            'key' => 'required|string|max:64',
            'label' => 'required|string|max:128',
            'sla_hours' => 'nullable|integer|min:1',
            'badge_color' => 'nullable|string|max:32',
            'description' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $lookup = $this->lookupService->saveLookup($validated, $request->user()?->id, $id);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'lookup' => $lookup]);
        }

        return back()->with('success', "Lookup '{$lookup->label}' updated successfully.");
    }

    /**
     * Delete lookup
     */
    public function destroy(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $this->lookupService->deleteLookup($id);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'message' => 'Lookup deleted successfully.']);
        }

        return back()->with('success', 'Lookup deleted successfully.');
    }
}
