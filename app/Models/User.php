<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\EmployeeAttendanceType;
use App\Models\HRM\Leave;
use App\Models\HRM\Offboarding;
use App\Models\NotificationToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * Class User
 *
 * @method bool hasRole(string|array $roles)
 * @method bool hasAnyRole(string|array $roles)
 * @method bool hasAllRoles(array $roles)
 * @method bool hasPermissionTo(string $permission, string $guardName = null)
 */
class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasRoles, InteractsWithMedia, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_id',
        'user_name',
        'phone',
        'email',
        'dob',
        'name',
        'profile_image',
        'date_of_joining',
        'birthday',
        'gender',
        'address',
        'about',
        'nid',
        'number_of_children',
        'emergency_contact_primary_name',
        'emergency_contact_primary_relationship',
        'emergency_contact_primary_phone',
        'emergency_contact_secondary_name',
        'emergency_contact_secondary_relationship',
        'emergency_contact_secondary_phone',
        'bank_name',
        'bank_account_no',
        'ifsc_code',
        'pan_no',
        'family_member_name',
        'family_member_relationship',
        'family_member_dob',
        'family_member_phone',
        'education_ug_institution',
        'education_ug_degree',
        'education_ug_start_year',
        'education_ug_end_year',
        'education_pg_institution',
        'education_pg_degree',
        'education_pg_start_year',
        'education_pg_end_year',
        'experience_1_company',
        'experience_1_position',
        'experience_1_start_date',
        'experience_1_end_date',
        'experience_2_company',
        'experience_2_position',
        'experience_2_start_date',
        'experience_2_end_date',
        'experience_3_company',
        'experience_3_position',
        'experience_3_start_date',
        'experience_3_end_date',
        'salary_basis',
        'salary_amount',
        'payment_type',
        'pf_contribution',
        'pf_no',
        'employee_pf_rate',
        'additional_pf_rate',
        'total_pf_rate',
        'esi_contribution',
        'esi_no',
        'employee_esi_rate',
        'additional_esi_rate',
        'total_esi_rate',
        'email_verified_at',
        'attendance_type_id',
        'work_location_id',
        'fcm_token',
        'preferences',
        'single_device_login_enabled',
        'device_reset_at',
        'device_reset_reason',
        'locale',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'report_to' => 'integer',
        'designation_id' => 'integer',
        'department_id' => 'integer',
        'attendance_type_id' => 'integer',
        'work_location_id' => 'integer',
        'preferences' => 'array',
        'single_device_login_enabled' => 'boolean',
        'device_reset_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_image_url',
    ];

    /**
     * Query scope to load basic relations for user management.
     */
    public function scopeWithBasicRelations($query)
    {
        return $query->with([
            'department:id,name',
            'designation:id,title',
            'roles:id,name',
        ]);
    }

    /**
     * Query scope to load device information.
     */
    public function scopeWithDeviceInfo($query)
    {
        return $query->with([
            'currentDevice:id,user_id,device_name,device_type,last_used_at,is_active',
        ]);
    }

    /**
     * Query scope to load full relations for detailed views.
     */
    public function scopeWithFullRelations($query)
    {
        return $query->with([
            'department',
            'designation',
            'roles',
            'attendanceType',
            'currentDevice',
            'educations',
            'experiences',
        ]);
    }

    public function notificationTokens(): HasMany
    {
        return $this->hasMany(NotificationToken::class);
    }

    public function ledProjects()
    {
        return $this->hasMany(Project::class, 'project_leader_id');
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_user');
    }

    public function experiences()
    {
        return $this->hasMany(Experience::class);
    }

    public function educations()
    {
        return $this->hasMany(Education::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class, 'user_id');
    }

    // In User.php model
    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'user_id');
    }

    public function attendanceType(): BelongsTo
    {
        return $this->belongsTo(AttendanceType::class, 'attendance_type_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    /**
     * Get resolved attendance type ID (profile override or inherited from location).
     */
    public function getAttendanceTypeIdAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }
        // Only inherit from the work location when it's already loaded — reading this
        // accessor during eager-load FK resolution must never trigger a lazy load.
        return $this->relationLoaded('workLocation') ? $this->workLocation?->attendance_type_id : null;
    }

    /**
     * Get resolved attendance type model (profile override or inherited from location).
     */
    public function getAttendanceTypeAttribute()
    {
        if ($this->getRawOriginal('attendance_type_id') !== null) {
            return $this->attendanceType()->getResults();
        }
        // Inherit from location only when loaded, to avoid lazy-loading violations.
        return $this->relationLoaded('workLocation') ? $this->workLocation?->attendanceType : null;
    }

    /**
     * Reliably resolve the effective attendance type (personal override → work-location
     * default) using explicit queries, so it works during punch validation regardless of
     * which relations are eager-loaded. This is the source of truth for attendance.
     */
    /**
     * Employee's personal override SET of attendance methods (multi-method).
     */
    public function attendanceTypes(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\HRM\AttendanceType::class, 'user_attendance_type');
    }

    /**
     * Zone model: the employee's override SUBSET of biometric devices (used only when the
     * employee has a personal override). Empty = fall back to location/type devices.
     */
    public function biometricDevices(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\HRM\BiometricDevice::class, 'user_biometric_device');
    }

    /**
     * Resolve the biometric device ids valid for this employee:
     *   override devices → work-location devices → [] (caller falls back to the type's devices).
     */
    public function resolvedBiometricDeviceIds(): array
    {
        $hasOverride = $this->getRawOriginal('attendance_type_id') !== null
            || ($this->relationLoaded('attendanceTypes') ? $this->attendanceTypes->isNotEmpty() : $this->attendanceTypes()->exists());

        if ($hasOverride) {
            return $this->biometricDevices()->pluck('biometric_devices.id')->all();
        }

        if ($this->work_location_id) {
            $location = $this->relationLoaded('workLocation') && $this->workLocation
                ? $this->workLocation
                : WorkLocation::find($this->work_location_id);

            return $location ? $location->biometricDevices()->pluck('biometric_devices.id')->all() : [];
        }

        return [];
    }

    /**
     * Resolve the effective SET of allowed attendance methods for this employee.
     * Precedence (override REPLACES inheritance):
     *   1. Employee override set (user_attendance_type pivot)
     *   2. Backward-compat single override FK (users.attendance_type_id)
     *   3. Work-location set (work_location_attendance_type pivot)
     *   4. Backward-compat single location FK (work_locations.attendance_type_id)
     * Returns only active types. A punch is valid if ANY of these validates.
     */
    public function resolvedAttendanceTypes(): \Illuminate\Support\Collection
    {
        $active = fn ($c) => $c->filter(fn ($t) => $t && $t->is_active)->values();

        // 1. Employee override set
        $override = $this->relationLoaded('attendanceTypes') ? $this->attendanceTypes : $this->attendanceTypes()->get();
        if ($override->isNotEmpty()) {
            return $active($override);
        }

        // 2. Backward-compat single override FK
        $rawId = $this->getRawOriginal('attendance_type_id');
        if ($rawId !== null) {
            return $active(collect([\App\Models\HRM\AttendanceType::find($rawId)]));
        }

        // 3 & 4. Inherit from work location (set, then single FK)
        if ($this->work_location_id) {
            $location = $this->relationLoaded('workLocation') && $this->workLocation
                ? $this->workLocation
                : WorkLocation::with('attendanceTypes')->find($this->work_location_id);
            if ($location) {
                $locSet = $location->relationLoaded('attendanceTypes') ? $location->attendanceTypes : $location->attendanceTypes()->get();
                if ($locSet->isNotEmpty()) {
                    return $active($locSet);
                }
                if ($location->attendance_type_id) {
                    return $active(collect([$location->attendanceType()->first()]));
                }
            }
        }

        return collect();
    }

    /**
     * Convenience: the primary resolved attendance type (first of the resolved set).
     */
    public function resolvedAttendanceType(): ?\App\Models\HRM\AttendanceType
    {
        return $this->resolvedAttendanceTypes()->first();
    }

    public function employeeAttendanceType(): HasOne
    {
        return $this->hasOne(EmployeeAttendanceType::class, 'user_id');
    }

    public function offboarding(): HasOne
    {
        return $this->hasOne(Offboarding::class, 'employee_id')
            ->where('status', '!=', Offboarding::STATUS_CANCELLED)
            ->orderByDesc('last_working_date');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Get the user's manager (who they report to).
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'report_to');
    }

    /**
     * Get the users who report to this user.
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'report_to');
    }

    /**
     * Get the user's devices.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * Get the user's active devices.
     */
    public function activeDevices()
    {
        return $this->hasMany(UserDevice::class)->active();
    }

    /**
     * Get the current active device.
     */
    public function currentDevice()
    {
        return $this->hasOne(UserDevice::class)->active()->latest('last_used_at');
    }

    /**
     * Get the active device (alias for currentDevice for compatibility).
     */
    public function activeDevice()
    {
        return $this->currentDevice();
    }

    /**
     * Check if single device login is enabled for this user.
     */
    public function hasSingleDeviceLoginEnabled(): bool
    {
        return (bool) $this->single_device_login_enabled;
    }

    /**
     * Accessor for single_device_login (for frontend compatibility).
     */
    public function getSingleDeviceLoginAttribute(): bool
    {
        return (bool) $this->single_device_login_enabled;
    }

    /**
     * Enable single device login for this user.
     */
    public function enableSingleDeviceLogin(?string $reason = null): bool
    {
        return $this->update([
            'single_device_login_enabled' => true,
            'device_reset_reason' => $reason,
        ]);
    }

    /**
     * Disable single device login for this user.
     */
    public function disableSingleDeviceLogin(?string $reason = null): bool
    {
        return $this->update([
            'single_device_login_enabled' => false,
            'device_reset_reason' => $reason,
        ]);
    }

    /**
     * Reset user devices (admin action).
     */
    public function resetDevices(?string $reason = null): bool
    {
        // Delete all devices for complete reset
        $this->devices()->delete();

        return $this->update([
            'device_reset_at' => now(),
            'device_reset_reason' => $reason ?: 'Admin reset',
        ]);
    }

    /**
     * Check if user can login from new device.
     */
    public function canLoginFromDevice(string $deviceId): bool
    {
        // If single device login is not enabled, allow login
        if (! $this->hasSingleDeviceLoginEnabled()) {
            return true;
        }

        // Check if this device is already registered and active
        $existingDevice = $this->devices()
            ->where('device_id', $deviceId)
            ->active()
            ->first();

        if ($existingDevice) {
            return true;
        }

        // Check if user has any active devices
        $hasActiveDevices = $this->activeDevices()->exists();

        // If no active devices, allow login (first device or after reset)
        return ! $hasActiveDevices;
    }

    /**
     * Get device summary for display.
     */
    public function getDeviceSummary(): array
    {
        $devices = $this->devices()->orderBy('last_used_at', 'desc')->get();

        return [
            'total_devices' => $devices->count(),
            'active_devices' => $devices->where('is_active', true)->count(),
            'current_device' => $devices->where('is_active', true)->first(),
            'last_reset' => $this->device_reset_at,
            'reset_reason' => $this->device_reset_reason,
            'single_device_enabled' => $this->single_device_login_enabled,
        ];
    }

    protected static function booted()
    {
        static::saved(function ($user) {
            if ($user->isDirty('attendance_type_id') || (!$user->getRawOriginal('attendance_type_id') && $user->isDirty('work_location_id'))) {
                $resolvedType = $user->getRawOriginal('attendance_type_id') ?: ($user->workLocation?->attendance_type_id);
                EmployeeAttendanceType::updateOrCreate(
                    ['user_id' => $user->id],
                    ['attendance_type_id' => $resolvedType]
                );
            }
        });
    }

    /**
     * Register media collections for the user.
     * Defines the profile_images collection with singleFile() to ensure only one profile image per user.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('profile_images')
            ->singleFile();
    }

    /**
     * Get the profile image URL.
     * Uses MediaLibrary standard methods with proper exception handling.
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        try {
            // Only use MediaLibrary - check if user has media in the profile_images collection
            $url = $this->getFirstMediaUrl('profile_images');

            return ! empty($url) ? $url : null;
        } catch (\Exception $e) {
            // Log the error and return null
            Log::warning('Failed to get profile image URL for user '.$this->id.': '.$e->getMessage());

            return null;
        }
    }
}
