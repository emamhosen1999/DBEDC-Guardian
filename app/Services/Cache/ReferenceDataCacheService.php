<?php

namespace App\Services\Cache;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReferenceDataCacheService
{
    /**
     * Cache TTL in seconds (24 hours)
     */
    const CACHE_TTL = 86400;

    /**
     * Get cached daily work statuses
     */
    public function getDailyWorkStatuses(): array
    {
        return Cache::remember('daily_work.statuses', self::CACHE_TTL, function () {
            return DailyWork::$statuses;
        });
    }

    /**
     * Get cached daily work types
     */
    public function getDailyWorkTypes(): array
    {
        return Cache::remember('daily_work.types', self::CACHE_TTL, function () {
            return DailyWork::$types;
        });
    }

    /**
     * Get cached daily work sides/road types
     */
    public function getDailyWorkSides(): array
    {
        return Cache::remember('daily_work.sides', self::CACHE_TTL, function () {
            return DailyWork::$sides;
        });
    }

    /**
     * Get cached inspection results
     */
    public function getInspectionResults(): array
    {
        return Cache::remember('daily_work.inspection_results', self::CACHE_TTL, function () {
            return DailyWork::$inspectionResults;
        });
    }

    /**
     * Get cached RFI response statuses
     */
    public function getRfiResponseStatuses(): array
    {
        return Cache::remember('daily_work.rfi_response_statuses', self::CACHE_TTL, function () {
            return DailyWork::$rfiResponseStatuses;
        });
    }

    /**
     * Get cached jurisdictions
     */
    public function getJurisdictions(): Collection
    {
        return Cache::remember('jurisdictions.all', self::CACHE_TTL, function () {
            return Jurisdiction::select('id', 'start_chainage', 'end_chainage', 'incharge')->get();
        });
    }

    /**
     * Get cached objection categories
     */
    public function getObjectionCategories(): array
    {
        return Cache::remember('rfi_objection.categories', self::CACHE_TTL, function () {
            return [
                RfiObjection::CATEGORY_DESIGN_CONFLICT => 'Design Conflict',
                RfiObjection::CATEGORY_SITE_MISMATCH => 'Site Mismatch',
                RfiObjection::CATEGORY_MATERIAL_CHANGE => 'Material Change',
                RfiObjection::CATEGORY_SAFETY_CONCERN => 'Safety Concern',
                RfiObjection::CATEGORY_SPECIFICATION_ERROR => 'Specification Error',
                RfiObjection::CATEGORY_OTHER => 'Other',
            ];
        });
    }

    /**
     * Get cached objection statuses
     */
    public function getObjectionStatuses(): array
    {
        return Cache::remember('rfi_objection.statuses', self::CACHE_TTL, function () {
            return RfiObjection::$statuses;
        });
    }

    /**
     * Get cached active user roles for permission checks
     */
    public function getActiveUserRoles(): Collection
    {
        return Cache::remember('users.active_roles', self::CACHE_TTL / 4, function () { // Shorter TTL for user data
            return User::where('active', true)
                ->with('roles:id,name')
                ->select('id', 'name', 'email')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->roles->pluck('name')->toArray(),
                    ];
                });
        });
    }

    /**
     * Get cached permission check result for a user
     */
    public function getUserPermissionCache(User $user, string $permission): ?bool
    {
        $cacheKey = "user.{$user->id}.permission.{$permission}";

        return Cache::remember($cacheKey, self::CACHE_TTL / 4, function () use ($user, $permission) {
            return $user->hasPermissionTo($permission);
        });
    }

    /**
     * Get cached role check result for a user
     */
    public function getUserRoleCache(User $user, string $role): ?bool
    {
        $cacheKey = "user.{$user->id}.role.{$role}";

        return Cache::remember($cacheKey, self::CACHE_TTL / 4, function () use ($user, $role) {
            return $user->hasRole($role);
        });
    }

    /**
     * Clear all reference data caches
     */
    public function clearAllCaches(): void
    {
        $cacheKeys = [
            'daily_work.statuses',
            'daily_work.types',
            'daily_work.sides',
            'daily_work.inspection_results',
            'daily_work.rfi_response_statuses',
            'jurisdictions.all',
            'rfi_objection.categories',
            'rfi_objection.statuses',
            'users.active_roles',
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Also clear user-specific caches (this is a broad clear)
        Cache::flush();
    }

    /**
     * Clear user-specific caches for a user
     */
    public function clearUserCaches(int $userId): void
    {
        // Clear permission and role caches for this user
        $patterns = [
            "user.{$userId}.permission.*",
            "user.{$userId}.role.*",
        ];

        // Note: In a production environment, you might want to use Redis SCAN or similar
        // For now, we'll clear the general user roles cache
        Cache::forget('users.active_roles');
    }

    /**
     * Warm up caches on application start
     */
    public function warmUpCaches(): void
    {
        // Pre-load all reference data
        $this->getDailyWorkStatuses();
        $this->getDailyWorkTypes();
        $this->getDailyWorkSides();
        $this->getInspectionResults();
        $this->getRfiResponseStatuses();
        $this->getJurisdictions();
        $this->getObjectionCategories();
        $this->getObjectionStatuses();
        $this->getActiveUserRoles();
    }
}