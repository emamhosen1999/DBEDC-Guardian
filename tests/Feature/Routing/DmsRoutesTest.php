<?php

namespace Tests\Feature\Routing;

use App\Http\Controllers\DMSController;
use Tests\TestCase;

class DmsRoutesTest extends TestCase
{
    public function test_dms_routes_are_registered_with_expected_actions(): void
    {
        $this->assertRouteAction('dms.index', DMSController::class.'@index');
        $this->assertRouteAction('dms.documents', DMSController::class.'@documents');
        $this->assertRouteAction('dms.documents.create', DMSController::class.'@create');
        $this->assertRouteAction('dms.documents.store', DMSController::class.'@store');
        $this->assertRouteAction('dms.documents.show', DMSController::class.'@show');
        $this->assertRouteAction('dms.documents.update', DMSController::class.'@update');
        $this->assertRouteAction('dms.documents.destroy', DMSController::class.'@destroy');
        $this->assertRouteAction('dms.categories', DMSController::class.'@categories');
        $this->assertRouteAction('dms.folders', DMSController::class.'@folders');
        $this->assertRouteAction('dms.access-control', DMSController::class.'@accessControl');
    }

    public function test_commercial_interaction_route_is_not_loaded_by_default(): void
    {
        $crmInteractionRoute = app('router')->getRoutes()->getByName('crm.interactions.index');

        $this->assertNull($crmInteractionRoute);
    }

    private function assertRouteAction(string $routeName, string $expectedAction): void
    {
        $route = app('router')->getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route [{$routeName}] was not registered.");
        $this->assertSame($expectedAction, $route->getActionName());
    }
}
