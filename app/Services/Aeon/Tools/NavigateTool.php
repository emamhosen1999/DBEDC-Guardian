<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Services\Aeon\Data\AeonAccess;
use App\Services\Aeon\Data\RouteAccess;
use Illuminate\Support\Str;

/**
 * Validated navigation tool that routes users directly to Guardian modules and pages.
 */
class NavigateTool implements AeonToolContract
{
    public function __construct(private AeonAccess $access) {}

    public function name(): string
    {
        return 'navigate';
    }

    public function description(): string
    {
        return 'Take the user directly to a specific module, page, or dashboard in DBEDC Guardian.';
    }

    public function parameters(): array
    {
        return [
            'destination' => [
                'type' => 'string',
                'description' => 'Target module or page (a module or page name, e.g. "attendance" or "leaves"); only pages the user may open are available',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Why this navigation is recommended',
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $dest = strtolower(trim((string) ($args['destination'] ?? '')));
        $modules = (array) config('modules', []);

        $actor = $this->access->actor($userId);

        // Candidates in priority order; the first page this user may actually open wins.
        $candidates = [];
        if (isset($modules[$dest])) {
            $candidates[] = $modules[$dest];
        }
        foreach ($modules as $key => $mod) {
            if ($dest !== '' && (str_contains((string) $key, $dest) || in_array($dest, $mod['keywords'] ?? [], true))) {
                $candidates[] = $mod;
            }
        }
        if (str_starts_with($dest, '/')) {
            $candidates[] = ['name' => Str::headline(trim($dest, '/')), 'route' => $dest];
        }

        $target = null;
        foreach ($candidates as $candidate) {
            if (RouteAccess::canOpen((string) ($candidate['route'] ?? '/'), $actor)) {
                $target = $candidate;
                break;
            }
        }

        if (! $target) {
            return [
                'text' => "Could not locate navigation path for '{$dest}'.",
                'blocks' => [],
                'data' => ['status' => 'error', 'destination' => $dest],
                'terminal' => false,
            ];
        }

        $routeName = (string) ($target['name'] ?? Str::headline($dest));
        $routeUrl = (string) ($target['route'] ?? '/');

        return [
            'text' => "Navigating to {$routeName} ({$routeUrl}).",
            'blocks' => [
                [
                    'type' => 'action',
                    'kind' => 'navigate',
                    'title' => "Go to {$routeName}",
                    'desc' => $args['reason'] ?? "Open the {$routeName} page",
                    'route' => $routeUrl,
                    'confirm_label' => 'Open Page →',
                ],
            ],
            'data' => ['status' => 'success', 'route' => $routeUrl],
            'terminal' => true,
        ];
    }
}
