<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private readonly WidgetRegistry $widgets) {}

    public function index()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }
        $user = Auth::user();

        // Someone holding ONLY the base roles (Employee, optionally with Daily Works Contributor) gets the employee dashboard
        if ($user->hasOnlyBaseRoles()) {
            return redirect()->route('employee-dashboard');
        }

        return Inertia::render('Dashboard', [
            'title' => 'Dashboard',
            // Deferred: the page paints at once and the registry (cached per employee, scope and section) follows.
            'widgets' => Inertia::defer(fn () => $this->widgets->payloadFor($user, DashboardWidget::DASHBOARD_MAIN)),
            'user' => $user,
            'status' => session('status'),
            'csrfToken' => session('csrfToken'),
        ]);
    }

    public function employeeIndex()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }
        $user = Auth::user();

        return Inertia::render('EmployeeDashboard', [
            'title' => 'Employee Dashboard',
            'widgets' => Inertia::defer(fn () => $this->widgets->payloadFor($user, DashboardWidget::DASHBOARD_EMPLOYEE)),
            'user' => $user,
            'status' => session('status'),
            'csrfToken' => session('csrfToken'),
        ]);
    }

    /**
     * Command-center payload for the Dashboard - read through the widget registry, the
     * same path the Inertia page and GET /api/v1/dashboard use.
     */
    public function command()
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $widget = $this->widgets->widgetFor($user, 'main.command');
        abort_if($widget === null || $widget['data'] === null, 403);

        return response()->json($widget['data']);
    }

    /**
     * JSON form of a dashboard's widgets (polling / refresh). Same contract as the
     * Inertia deferred prop and GET /api/v1/dashboard.
     */
    public function widgets(Request $request)
    {
        $section = $request->query('section', DashboardWidget::DASHBOARD_EMPLOYEE);
        abort_unless(in_array($section, [DashboardWidget::DASHBOARD_EMPLOYEE, DashboardWidget::DASHBOARD_MAIN], true), 422);
        $user = $request->user();
        abort_if($section === DashboardWidget::DASHBOARD_MAIN && ! $user->can('core.dashboard.view'), 403);

        return response()->json($this->widgets->payloadFor($user, $section));
    }

    public function stats()
    {
        $user = Auth::user();
        $version = Cache::get('daily_works_cache_version', 1);
        $cacheKey = "dashboard_stats_user_{$user->id}_v{$version}";

        $statistics = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user) {
            // Use permission-based access control instead of roles
            $taskQuery = DailyWork::query();

            // Apply filters based on user permissions and context
            if ($user->can('daily-works.view')) {
                // Users with full daily works access can see all tasks
                $taskQuery = DailyWork::query();
            } elseif ($user->can('daily-works.own.view')) {
                // Users with limited access see only their own tasks
                $taskQuery = DailyWork::where(function ($query) use ($user) {
                    $query->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);
                });
            } else {
                // No access to daily works - return empty stats
                $taskQuery = DailyWork::whereRaw('1 = 0'); // Always empty
            }

            $total = (clone $taskQuery)->count();
            $completed = (clone $taskQuery)->where('status', 'completed')->count();
            $pending = $total - $completed;
            $rfi_submissions = (clone $taskQuery)->whereNotNull('rfi_submission_date')->count();

            return [
                'total' => $total,
                'completed' => $completed,
                'pending' => $pending,
                'rfi_submissions' => $rfi_submissions,
            ];
        });

        return response()->json([
            'statistics' => $statistics,
        ]);
    }

    public function updates()
    {
        $user = Auth::user();

        // Check if user has permission to view updates
        if (! $user->can('core.updates.view')) {
            return response()->json([
                'message' => 'Unauthorized access to updates',
            ], 403);
        }

        // Non-global actors see only their managed departments / reporting subtree / self.
        $scope = app(DepartmentScope::class);
        $visibleIds = $scope->visibleEmployeeIds($user);

        $users = $scope->applyToUsers(
            User::with('roles:name')->whereHas('roles', function ($query) {
                $query->where('name', 'Employee');
            }),
            $user
        )
            ->get()
            ->map(function ($user) {
                $userData = $user->toArray();
                $userData['roles'] = $user->roles->pluck('name')->toArray();

                return $userData;
            });

        $today = now()->toDateString();

        // Only show leave information if user has appropriate permissions
        $todayLeaves = [];
        $upcomingLeaves = [];

        if ($user->can('leaves.view') || $user->can('leave.own.view')) {
            $leaveQuery = DB::table('leaves')
                ->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                ->select('leaves.*', 'leave_settings.type as leave_type');

            // If user can only view own leaves, filter accordingly
            if (! $user->can('leaves.view') && $user->can('leave.own.view')) {
                $leaveQuery->where('leaves.user_id', $user->id);
            } elseif ($visibleIds !== null) {
                $leaveQuery->whereIn('leaves.user_id', $visibleIds === [] ? ['__NONE__'] : $visibleIds);
            }

            $todayLeaves = (clone $leaveQuery)
                ->whereDate('leaves.from_date', '<=', $today)
                ->whereDate('leaves.to_date', '>=', $today)
                ->get();

            $upcomingLeaves = (clone $leaveQuery)
                ->where(function ($query) {
                    $query->whereDate('leaves.from_date', '>=', now())
                        ->orWhereDate('leaves.to_date', '>=', now());
                })
                ->where(function ($query) {
                    $query->whereDate('leaves.from_date', '<=', now()->addDays(7))
                        ->orWhereDate('leaves.to_date', '<=', now()->addDays(7));
                })
                ->orderBy('leaves.from_date', 'desc')
                ->get();
        }

        $upcomingHolidays = [];
        if ($user->can('holidays.view')) {
            $upcomingHolidays = DB::table('holidays')
                ->whereDate('holidays.from_date', '>=', now())
                ->orderBy('holidays.from_date', 'asc')
                ->limit(3)
                ->get();
        }

        return response()->json([
            'users' => $users,
            'todayLeaves' => $todayLeaves,
            'upcomingLeaves' => $upcomingLeaves,
            'upcomingHolidays' => $upcomingHolidays,
        ]);
    }
}
