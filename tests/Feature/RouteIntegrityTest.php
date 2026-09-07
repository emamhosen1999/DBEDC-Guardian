<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteIntegrityTest extends TestCase
{
    public function test_every_controller_route_targets_an_existing_method(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $action, 2);
            if (! class_exists($controller) || ! method_exists($controller, $method)) {
                $missing[] = $route->uri().' => '.$action;
            }
        }

        $this->assertSame([], $missing, "Routes with missing controller actions:\n".implode("\n", $missing));
    }

    public function test_literal_frontend_ziggy_calls_reference_registered_routes(): void
    {
        $missing = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if (in_array($file->getFilename(), ['ziggy.js', 'ziggy.d.ts'], true)) {
                continue;
            }

            $contents = $file->getContents();
            preg_match_all('/(?:router\.)?route\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $matches);

            foreach (array_unique($matches[1] ?? []) as $routeName) {
                if (! Route::has($routeName)) {
                    $missing[] = $file->getRelativePathname().' => '.$routeName;
                }
            }
        }

        sort($missing);
        $this->assertSame([], $missing, "Frontend references missing routes:\n".implode("\n", $missing));
    }
}
