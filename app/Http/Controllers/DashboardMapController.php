<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\WidgetRegistry;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /dashboard/map - the corridor map payload for a date or range (the maximized card's filters).
 * Same permission as the Dashboard; every layer inside re-checks its own permission and scope.
 */
class DashboardMapController extends Controller
{
    public function __construct(private readonly MapLayerRegistry $layers, private readonly WidgetRegistry $widgets) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:80'],
            'layers' => ['nullable', 'array', 'max:60'],
            'layers.*' => ['string', 'max:64'],
        ]);

        $user = $request->user();

        return response()->json($this->layers->payloadFor($user, MapFilter::fromRequest($request), $this->widgets->scopeFingerprint($user)));
    }
}
