<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/dashboard?section=employee|main - the same widget registry payload the web
 * dashboards receive (Inertia deferred prop / GET /dashboard/widgets), so the two clients
 * can never disagree about who sees what. The `main` section needs core.dashboard.view.
 */
class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request, WidgetRegistry $widgets): JsonResponse
    {
        $section = $request->query('section', DashboardWidget::DASHBOARD_EMPLOYEE);

        if (! in_array($section, [DashboardWidget::DASHBOARD_EMPLOYEE, DashboardWidget::DASHBOARD_MAIN], true)) {
            return $this->validationErrorResponse(['section' => ['The section must be employee or main.']]);
        }

        $user = $request->user();
        if ($section === DashboardWidget::DASHBOARD_MAIN && ! $user->can('core.dashboard.view')) {
            return $this->forbiddenResponse('You do not have access to the main dashboard.');
        }

        return $this->successResponse($widgets->payloadFor($user, $section));
    }
}
