<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Asset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    /**
     * List all company assets with filtering and statistics.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $query = Asset::with(['assignee:employee_id,name,department_id,designation_id'])
            ->when($request->input('category'), fn ($q, $cat) => $q->where('category', $cat))
            ->when($request->input('status'), fn ($q, $st) => $q->where('status', $st))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('asset_code', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhereHas('assignee', fn ($eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('employee_id', 'like', "%{$search}%"));
                });
            });

        $assets = $query->orderByDesc('created_at')->paginate($request->input('per_page', 20));

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($assets);
        }

        $stats = [
            'total' => Asset::count(),
            'assigned' => Asset::where('status', Asset::STATUS_ASSIGNED)->count(),
            'available' => Asset::where('status', Asset::STATUS_AVAILABLE)->count(),
            'damaged' => Asset::where('status', Asset::STATUS_DAMAGED)->count(),
        ];

        return Inertia::render('HR/Assets', [
            'title' => 'Company Asset Management',
            'assets' => $assets,
            'stats' => $stats,
            'filters' => $request->only(['category', 'status', 'search']),
        ]);
    }

    /**
     * Create / register a new asset.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_code' => 'required|string|unique:assets,asset_code',
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:it_hardware,sim_card,access_card,safety_gear,keys,vehicle,other',
            'serial_number' => 'nullable|string|max:100',
            'assignee_id' => 'nullable|exists:users,employee_id',
            'condition_on_issue' => 'nullable|string|in:new,good,fair',
            'notes' => 'nullable|string',
        ]);

        if (! empty($validated['assignee_id'])) {
            $validated['status'] = Asset::STATUS_ASSIGNED;
            $validated['assigned_date'] = now();
        } else {
            $validated['status'] = Asset::STATUS_AVAILABLE;
        }

        $asset = Asset::create($validated);

        return response()->json([
            'message' => 'Asset registered successfully.',
            'asset' => $asset->load('assignee'),
        ], 201);
    }

    /**
     * Update asset information.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:it_hardware,sim_card,access_card,safety_gear,keys,vehicle,other',
            'serial_number' => 'nullable|string|max:100',
            'condition_on_issue' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $asset->update($validated);

        return response()->json([
            'message' => 'Asset updated successfully.',
            'asset' => $asset->fresh('assignee'),
        ]);
    }

    /**
     * Assign asset to an employee (e.g. during onboarding).
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'employee_id' => 'required|exists:users,employee_id',
            'condition_on_issue' => 'nullable|string|in:new,good,fair',
            'notes' => 'nullable|string',
        ]);

        $asset->update([
            'assignee_id' => $validated['employee_id'],
            'assigned_date' => now(),
            'return_date' => null,
            'status' => Asset::STATUS_ASSIGNED,
            'condition_on_issue' => $validated['condition_on_issue'] ?? $asset->condition_on_issue ?? 'good',
            'condition_on_return' => null,
            'notes' => $validated['notes'] ?? $asset->notes,
        ]);

        return response()->json([
            'message' => "Asset {$asset->asset_code} assigned to employee.",
            'asset' => $asset->fresh('assignee'),
        ]);
    }

    /**
     * Return asset from employee (e.g. during offboarding clearance).
     */
    public function returnAsset(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'condition_on_return' => 'required|string|in:good,fair,damaged,lost',
            'notes' => 'nullable|string',
        ]);

        $asset->update([
            'return_date' => now(),
            'status' => $validated['condition_on_return'] === 'damaged' ? Asset::STATUS_DAMAGED : Asset::STATUS_AVAILABLE,
            'condition_on_return' => $validated['condition_on_return'],
            'assignee_id' => null,
            'notes' => $validated['notes'] ? ($asset->notes . "\nReturn Note: " . $validated['notes']) : $asset->notes,
        ]);

        return response()->json([
            'message' => "Asset {$asset->asset_code} marked as returned.",
            'asset' => $asset,
        ]);
    }

    /**
     * List all assets currently assigned to a specific employee.
     */
    public function byEmployee(string $employeeId): JsonResponse
    {
        $assets = Asset::where('assignee_id', $employeeId)
            ->where('status', Asset::STATUS_ASSIGNED)
            ->get();

        return response()->json($assets);
    }

    /**
     * Delete an asset.
     */
    public function destroy(int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $asset->delete();

        return response()->json(['message' => 'Asset deleted successfully.']);
    }
}
