<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Aeon\AeonService;
use App\Services\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Check if route requires authentication and redirect immediately if not authenticated
        // This prevents sharing any authenticated data to unauthenticated users
        if (! $request->user() && ! $this->isPublicRoute($request)) {
            // For Inertia requests, we need to handle this carefully
            // The auth middleware will handle the actual redirect
            // But we ensure no authenticated data is shared
        }

        $user = $request->user();
        $userWithRelations = $user ? User::with([
            'designation',
            'attendanceType.biometricDevices:id,name',
            'employeeAttendanceType.biometricDevice:id,name',
        ])->find($user->employee_id ?? $user->getKey()) : null;

        // Get company settings for global use
        $companySettings = CompanySetting::first();
        $companyName = $companySettings?->companyName ?? config('app.name', 'DBEDC ERP');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $userWithRelations ? [
                    ...$userWithRelations->toArray(),
                    'attendance_type' => $userWithRelations->attendanceType ? [
                        'id' => $userWithRelations->attendanceType->id,
                        'name' => $userWithRelations->attendanceType->name,
                        'slug' => $userWithRelations->attendanceType->slug,
                        'config' => $userWithRelations->attendanceType->config ?? [],
                    ] : null,
                    'attendance_type_devices' => $userWithRelations->attendanceType
                        ?->biometricDevices
                        ?->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
                        ->values()->toArray() ?? [],
                    'biometric_device_name' => $userWithRelations->employeeAttendanceType?->biometricDevice?->name,
                ] : null,
                'isAuthenticated' => (bool) $user,
                'mustChangePassword' => (bool) ($user?->must_change_password),
                'sessionValid' => $user && $request->session()->isStarted(),
                'roles' => $user ? $user->roles->pluck('name')->toArray() : [],
                // Super Administrator authority comes from a Gate::before bypass (AuthServiceProvider),
                // not from explicitly-assigned permissions — so getAllPermissions() would NOT include
                // abilities like attendance.manage for them. The frontend gates UI on
                // permissions.includes(...), so without this a Super Admin silently loses admin UI
                // (e.g. the attendance Approvals tab). Mirror the backend bypass: hand Super Admins
                // the full permission list so every client-side gate matches their real authority.
                'permissions' => $user
                    ? ($user->hasRole('Super Administrator')
                        ? Permission::query()->pluck('name')->unique()->values()->toArray()
                        : $user->getAllPermissions()->pluck('name')->toArray())
                    : [],
                'isSuperAdmin' => $user ? $user->hasRole('Super Administrator') : false,
                // Which departments this actor operates on. Department pickers and filters render
                // from this (one shared component): global -> the full list, one department ->
                // locked to it, several -> a limited list. The server enforces the same scope on
                // every query; this only keeps the UI from offering what would be refused.
                'scope' => $user ? $this->scopeProps($user) : ['global' => false, 'attendance' => false, 'departments' => []],
                'designation' => $userWithRelations?->designation?->title,
                // Navigation is built client-side from resources/js/Props/pages.jsx (see Layouts/App.jsx).
                // The legacy DB-driven Module Permission Registry nav is disabled: as a bare Inertia v2
                // closure it was evaluated eagerly on every request, causing a LazyLoadingViolation in dev
                // (SubModule->module) and an N+1 over the module tree in prod. Kept as [] for consumers.
                'accessibleModules' => [],
            ],

            // Server-side feature flags the UI needs to hide unfinished modules.
            'features' => [
                'hr_payroll' => app(FeatureFlagService::class)->isEnabled('hr_payroll', $user, false),
                'hr_final_settlement' => app(FeatureFlagService::class)->isEnabled('hr_final_settlement', $user, false),
            ],

            // Company Settings
            'companySettings' => $companySettings,

            // Theme and UI Configuration
            'theme' => [
                'defaultTheme' => 'OCEAN',
                'defaultBackground' => 'pattern-1',
                'darkMode' => false,
                'animations' => true,
            ],

            // Application Configuration
            'app' => [
                'name' => $companyName,
                'copyright' => config('app.copyright'),
                'version' => config('app.version', '1.0.0'),
                'debug' => config('app.debug', false),
                'environment' => config('app.env', 'production'),
            ],

            // Realtime (RTDB) — single source of truth for the signal namespace, so the
            // client subscribes to exactly what the server publishes to. Avoids the
            // server-config vs VITE_-env drift that would silently break realtime.
            'realtime' => [
                'namespace' => config('realtime.namespace'),
            ],

            // Aeon AI Assistant Status & Usage
            'aeon' => [
                'available' => (bool) config('aeon.enabled', true),
                'usage' => $user ? app(AeonService::class)->getUsageStatus($user->id) : null,
            ],

            'url' => $request->getPathInfo(),
            'csrfToken' => session('csrfToken'),

            // Localization - shared on every request
            'locale' => App::getLocale(),
            'fallbackLocale' => config('app.fallback_locale', 'en'),
            'supportedLocales' => SetLocale::getSupportedLocales(),
            'translations' => fn () => $this->getTranslations(),
        ];
    }

    /**
     * The actor's department scope for the UI: `global` actors are unrestricted (the client
     * falls back to each page's full list), everyone else gets exactly the departments they
     * may pick from. `attendance` marks an attendance administrator (global, or holding
     * `attendance.settings`): company-wide on the attendance pages even when their people
     * scope is not.
     *
     * @return array{global: bool, attendance: bool, departments: array<int, array{id: int, name: string}>}
     */
    protected function scopeProps(User $user): array
    {
        $scope = app(DepartmentScope::class);
        $attendance = $scope->isAttendanceAdmin($user);

        if ($scope->isGlobal($user)) {
            return ['global' => true, 'attendance' => true, 'departments' => []];
        }

        $ids = $scope->visibleDepartmentIds($user);

        return [
            'global' => false,
            'attendance' => $attendance,
            'departments' => $ids === []
                ? []
                : Department::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Department $department) => ['id' => (int) $department->id, 'name' => $department->name])
                    ->values()
                    ->all(),
        ];
    }

    /**
     * Get translations for the current locale.
     *
     * Translations are loaded lazily to avoid performance impact on every request.
     * Only the necessary namespaces are loaded based on the current route.
     *
     * @return array<string, mixed>
     */
    protected function getTranslations(): array
    {
        $locale = App::getLocale();
        $translations = [];

        // Always load common translations
        $namespaces = ['common', 'navigation', 'validation'];

        // Add route-specific translations
        $routeName = request()->route()?->getName() ?? '';
        if (str_contains($routeName, 'dashboard')) {
            $namespaces[] = 'dashboard';
        }
        if (str_contains($routeName, 'employee') || str_contains($routeName, 'department') || str_contains($routeName, 'designation') || str_contains($routeName, 'leave') || str_contains($routeName, 'attendance')) {
            $namespaces[] = 'hr';
        }
        if (str_contains($routeName, 'device')) {
            $namespaces[] = 'device';
        }

        // Load PHP translation files
        foreach ($namespaces as $namespace) {
            $path = lang_path("{$locale}/{$namespace}.php");
            if (file_exists($path)) {
                $translations[$namespace] = require $path;
            }
        }

        // Load JSON translations (flat keys for simple lookups)
        $jsonPath = lang_path("{$locale}.json");
        if (file_exists($jsonPath)) {
            $jsonTranslations = json_decode(file_get_contents($jsonPath), true);
            if ($jsonTranslations) {
                $translations = array_merge($translations, $jsonTranslations);
            }
        }

        return $translations;
    }

    /**
     * Check if the current route is public (doesn't require authentication).
     */
    protected function isPublicRoute(Request $request): bool
    {
        $publicRoutes = [
            'login',
            'register',
            'password.request',
            'password.reset',
            'password.email',
            'password.update',
            'verification.notice',
        ];

        $currentRoute = $request->route();

        if (! $currentRoute) {
            return false;
        }

        $routeName = $currentRoute->getName();

        return in_array($routeName, $publicRoutes) ||
               str_starts_with($request->path(), 'login') ||
               str_starts_with($request->path(), 'register') ||
               str_starts_with($request->path(), 'forgot-password') ||
               str_starts_with($request->path(), 'reset-password');
    }
}
