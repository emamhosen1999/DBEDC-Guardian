<?php

namespace App\Http\Controllers;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\SelfAdministration;
use App\Services\Profile\ProfileCrudService;
use App\Services\Profile\ProfileUpdateService;
use App\Services\Profile\ProfileValidationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProfileController extends Controller
{
    /** Salary and statutory columns: shown only to someone allowed to see this employee's compensation. */
    private const COMPENSATION_FIELDS = [
        'salary_basis', 'salary_amount', 'payment_type',
        'pf_contribution', 'pf_no', 'employee_pf_rate', 'additional_pf_rate', 'total_pf_rate',
        'esi_contribution', 'esi_no', 'employee_esi_rate', 'additional_esi_rate', 'total_esi_rate',
    ];

    protected ProfileValidationService $validationService;

    protected ProfileCrudService $crudService;

    protected ProfileUpdateService $updateService;

    public function __construct(
        ProfileValidationService $validationService,
        ProfileCrudService $crudService,
        ProfileUpdateService $updateService
    ) {
        $this->validationService = $validationService;
        $this->crudService = $crudService;
        $this->updateService = $updateService;
    }

    /**
     * Display the user's profile form.
     */
    public function index(Request $request, User $user): Response
    {
        $this->authorizeProfileView($request, $user);

        $actor = $request->user();
        $scope = app(DepartmentScope::class);
        // Placing someone (department / designation / reporting line): employees.placement.update (or
        // a transfer) AND scope over them — and never one's own. The pickers hold only what the actor
        // may pick from: their own people and departments, not the whole company.
        $canManageEmployment = $actor->can('updatePlacement', $user) || $actor->can('transfer', [$user]);
        $reportTo = User::find($user->report_to);
        $userDetails = $this->crudService->getUserWithDetails($user->employee_id ?? $user->getKey());

        // Salary and statutory details only for someone allowed to see them (one's own always).
        $canViewCompensation = $actor->can('viewCompensation', $user);
        if (! $canViewCompensation) {
            $userDetails?->makeHidden(self::COMPENSATION_FIELDS);
        }

        return Inertia::render('Profile/UserProfile', [
            'title' => 'Profile',
            'user' => $userDetails,
            'allUsers' => $canManageEmployment
                ? $scope->applyToUsers(User::select('employee_id as id', 'employee_id', 'name', 'department_id', 'designation_id')->with('roles:id,name'), $actor)->get()
                : [],
            'departments' => $canManageEmployment ? $scope->applyToDepartments(Department::query(), $actor)->get() : [],
            'designations' => $canManageEmployment
                ? ($scope->isGlobal($actor) ? Designation::all() : Designation::whereIn('department_id', $scope->visibleDepartmentIds($actor))->get())
                : [],
            'report_to' => $reportTo,
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'can' => [
                'edit' => $this->canUpdateProfile($request, $user),
                'manageEmployment' => $canManageEmployment,
                'viewCompensation' => $canViewCompensation,
                'manageCompensation' => $actor->can('updateCompensation', $user),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->can('employees.create'), 403);

        // Validate the incoming request
        $validator = $this->validationService->validateUserCreation($request);

        // If validation fails, return the errors
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Create a new user
        $user = $this->crudService->createUser($validator->validated());

        // Return a success response with the created user data
        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user,
        ], 201);
    }

    /**
     * Update the user's profile information.
     * Note: Profile image upload/removal is now handled by ProfileImageController
     */
    public function update(Request $request)
    {
        try {
            // Validate the request (excluding profile image handling)
            $validated = $this->validationService->validateUserUpdate($request);
            $user = $this->crudService->findUser((string) $validated['id']);
            abort_if(! $user, 404);

            $this->authorizeProfileUpdate($request, $user);
            $this->guardEmploymentFields($request, $user, $validated);

            $selfAdmin = app(SelfAdministration::class);
            $audited = ['department', 'designation', 'report_to', 'salary_amount', 'salary_basis', 'payment_type'];
            $selfChanges = [];
            if ($selfAdmin->allows($request->user(), $user)) {
                foreach (array_intersect($audited, array_keys($validated)) as $input) {
                    $attribute = ['department' => 'department_id', 'designation' => 'designation_id'][$input] ?? $input;
                    if ((string) ($validated[$input] ?? '') !== (string) ($user->{$attribute} ?? '')) {
                        $selfChanges[$input] = [$user->{$attribute}, $validated[$input]];
                    }
                }
            }

            // Update user profile
            $messages = $this->updateService->updateUserProfile($user, $validated);

            // Save the user
            $this->crudService->saveUser($user);

            if ($selfChanges !== []) {
                $selfAdmin->record($request->user(), 'profile.update', 'updated his own '.implode(', ', array_keys($selfChanges)), 'user', $user->employee_id, $selfChanges);
            }

            // Get fresh user data with profile image URL
            $freshUser = $user->fresh();

            return response()->json([
                'messages' => $messages,
                'user' => $freshUser,
                'profile_image_url' => $freshUser->profile_image_url, // Explicitly include accessor
            ]);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get profile statistics for dashboard cards
     */
    public function stats(Request $request, User $user)
    {
        $this->authorizeProfileView($request, $user);

        try {
            // Cache key for user stats
            $cacheKey = "profile_stats_{$user->id}";

            $stats = Cache::remember($cacheKey, 3600, function () use ($user) {
                // Calculate profile completion percentage
                $sections = [
                    'basic_info' => $user->name && $user->email,
                    'contact_info' => $user->phone && $user->address,
                    'personal_info' => $user->birthday && $user->gender,
                    'work_info' => $user->department && $user->designation,
                    'emergency_contact' => $user->emergency_contact_primary_name,
                    'bank_info' => $user->bank_name || $user->bank_account_no,
                    'education' => $user->educations && $user->educations->count() > 0,
                    'experience' => $user->experiences && $user->experiences->count() > 0,
                ];

                $completed = collect($sections)->filter()->count();
                $total = count($sections);
                $completion_percentage = round(($completed / $total) * 100);

                // Get profile views (if tracking is implemented)
                $profile_views = DB::table('profile_views')
                    ->where('user_id', $user->id)
                    ->count() ?? 0;

                return [
                    'completion_percentage' => $completion_percentage,
                    'total_sections' => $total,
                    'completed_sections' => $completed,
                    'last_updated' => $user->updated_at,
                    'profile_views' => $profile_views,
                    'sections_status' => $sections,
                ];
            });

            return response()->json([
                'success' => true,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export profile data (consistent with other modules)
     */
    public function export(Request $request, User $user)
    {
        try {
            $this->authorizeProfileView($request, $user);

            $profileData = $this->crudService->getUserWithDetails($user->id);

            // Format data for export
            $exportData = [
                'basic_information' => [
                    'name' => $profileData->name,
                    'email' => $profileData->email,
                    'phone' => $profileData->phone,
                    'employee_id' => $profileData->employee_id,
                    'date_of_joining' => $profileData->date_of_joining,
                ],
                'personal_information' => [
                    'birthday' => $profileData->birthday,
                    'gender' => $profileData->gender,
                    'address' => $profileData->address,
                    'nationality' => $profileData->nationality,
                    'religion' => $profileData->religion,
                    'marital_status' => $profileData->marital_status,
                ],
                'work_information' => [
                    'department' => $profileData->department,
                    'designation' => $profileData->designation,
                    'report_to' => $profileData->report_to,
                ],
                'emergency_contacts' => [
                    'primary' => [
                        'name' => $profileData->emergency_contact_primary_name,
                        'relationship' => $profileData->emergency_contact_primary_relationship,
                        'phone' => $profileData->emergency_contact_primary_phone,
                    ],
                    'secondary' => [
                        'name' => $profileData->emergency_contact_secondary_name,
                        'relationship' => $profileData->emergency_contact_secondary_relationship,
                        'phone' => $profileData->emergency_contact_secondary_phone,
                    ],
                ],
                'bank_information' => [
                    'bank_name' => $profileData->bank_name,
                    'account_number' => $profileData->bank_account_no,
                    'ifsc_code' => $profileData->ifsc_code,
                    'pan_number' => $profileData->pan_no,
                ],
                'education' => $profileData->educations->toArray(),
                'experience' => $profileData->experiences->toArray(),
                'exported_at' => now()->toISOString(),
                'exported_by' => Auth::user()->name,
            ];

            return response()->json([
                'success' => true,
                'data' => $exportData,
                'filename' => "profile_{$user->name}_".now()->format('Y-m-d_H-i-s').'.json',
            ]);

        } catch (HttpExceptionInterface|AuthorizationException $e) {
            throw $e; // a refused export is a 403, not a "failed export"
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export profile data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search profiles (for admin usage - consistent with other modules)
     */
    public function search(Request $request)
    {
        try {
            $query = User::with(['department', 'designation']);

            // Search filters
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            if ($request->filled('department')) {
                $query->where('department', $request->department);
            }

            if ($request->filled('designation')) {
                $query->where('designation', $request->designation);
            }

            if ($request->filled('status')) {
                if ($request->status === 'active') {
                    $query->whereNull('deleted_at');
                } elseif ($request->status === 'inactive') {
                    $query->whereNotNull('deleted_at');
                }
            }

            // Sorting — only ever order by a column we have named here. An
            // unknown column reaches the database as invalid SQL, and an unknown
            // direction makes orderBy() throw, so both are resolved to a default
            // rather than trusted.
            $allowedSorts = ['name', 'email', 'employee_id', 'phone', 'created_at'];

            $sortField = in_array($request->get('sort_field'), $allowedSorts, true)
                ? $request->get('sort_field')
                : 'name';

            $sortDirection = strtolower((string) $request->get('sort_direction')) === 'desc'
                ? 'desc'
                : 'asc';

            $query->orderBy($sortField, $sortDirection);

            // Pagination — clamped so a hand-edited ?per_page= cannot ask the
            // database for the whole table.
            $perPage = min(max((int) $request->get('per_page', 15), 5), 100);
            $profiles = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $profiles->items(),
                'pagination' => [
                    'current_page' => $profiles->currentPage(),
                    'last_page' => $profiles->lastPage(),
                    'per_page' => $profiles->perPage(),
                    'total' => $profiles->total(),
                    'from' => $profiles->firstItem(),
                    'to' => $profiles->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search profiles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track profile view (for analytics)
     */
    public function trackView(Request $request, User $user)
    {
        $this->authorizeProfileView($request, $user);

        try {
            // Only track if viewer is different from profile owner
            if (Auth::id() !== $user->id) {
                DB::table('profile_views')->insert([
                    'user_id' => $user->id,
                    'viewer_id' => Auth::id(),
                    'viewed_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                // Clear cache to refresh stats
                Cache::forget("profile_stats_{$user->id}");
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            // Fail silently for tracking
            return response()->json(['success' => false], 200);
        }
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function authorizeProfileView(Request $request, User $target): void
    {
        // Own profile with profile.own.view; anyone else's with the directory permission AND scope. Someone
        // else's profile outside the actor's reach reads exactly like a profile that does not exist.
        $isSelf = (string) $request->user()?->getKey() === (string) $target->getKey();
        abort_unless($request->user()?->can('viewProfile', $target), $isSelf ? 403 : 404);
    }

    private function authorizeProfileUpdate(Request $request, User $target): void
    {
        abort_unless($this->canUpdateProfile($request, $target), 403);
    }

    private function canUpdateProfile(Request $request, User $target): bool
    {
        // Own profile with profile.own.update; anyone else's with employees.update AND scope over them.
        return (bool) $request->user()?->can('updateProfile', $target);
    }

    /**
     * Profile writes must not alter employment identity, hierarchy or compensation unless the actor
     * holds the matching granular permission over THIS employee: salary -> employees.compensation.update,
     * department -> a transfer inside his departments, designation / reporting line ->
     * employees.placement.update. None of them is ever allowed on one's own record (a Super
     * Administrator aside).
     */
    private function guardEmploymentFields(Request $request, User $target, array $validated): void
    {
        $actor = $request->user();

        if (($request->input('ruleSet', 'profile')) === 'salary') {
            abort_unless($actor->can('updateCompensation', $target), 403, 'You are not allowed to change this employee\'s salary.');

            return;
        }

        $changed = fn (string $input, string $attribute): bool => array_key_exists($input, $validated)
            && (string) ($validated[$input] ?? '') !== (string) ($target->{$attribute} ?? '');
        $scope = app(DepartmentScope::class);

        if ($changed('department', 'department_id')) {
            abort_unless($actor->can('transfer', [$target, (int) $validated['department']]), 403, 'You can only move employees between departments you manage.');
        }

        if ($changed('designation', 'designation_id') || $changed('report_to', 'report_to')) {
            abort_unless($actor->can('updatePlacement', $target), 403, 'Designation and reporting line require placement permission over this employee.');

            if (! $scope->isGlobal($actor)) {
                if ($changed('designation', 'designation_id')
                    && ! in_array((int) Designation::whereKey($validated['designation'])->value('department_id'), $scope->managedDepartmentIds($actor), true)) {
                    abort(403, 'Unauthorized to assign designations outside your department scope.');
                }
                if ($changed('report_to', 'report_to') && ! $scope->canActOn($actor, (string) $validated['report_to'], allowSelf: true)) {
                    abort(403, 'The reporting manager must be within your department scope.');
                }
            }
        }

        if ($changed('date_of_joining', 'date_of_joining')) {
            abort_unless((string) $actor->getKey() !== (string) $target->getKey() || $actor->hasRole('Super Administrator'), 403, 'You cannot change your own joining date.');
        }

        if ($changed('employee_id', 'employee_id')) {
            abort_unless($actor->hasRole(['Super Administrator', 'Administrator']), 403, 'Only an Administrator may change an employee ID.');
        }
    }
}
