<?php

namespace App\Traits;

use App\Services\Cache\ReferenceDataCacheService;
use Illuminate\Support\Facades\Cache;

/**
 * Trait for cached permission and role checks
 * Reduces database queries by caching permission results
 */
trait CachedPermissions
{
    /**
     * Check if user has permission with caching
     */
    public function hasPermissionCached(string $permission): bool
    {
        $cacheService = app(ReferenceDataCacheService::class);
        return $cacheService->getUserPermissionCache($this, $permission) ?? false;
    }

    /**
     * Check if user has role with caching
     */
    public function hasRoleCached(string|array $roles): bool
    {
        $cacheService = app(ReferenceDataCacheService::class);

        if (is_string($roles)) {
            return $cacheService->getUserRoleCache($this, $roles) ?? false;
        }

        // Check multiple roles
        foreach ($roles as $role) {
            if ($cacheService->getUserRoleCache($this, $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the specified roles with caching
     */
    public function hasAnyRoleCached(array $roles): bool
    {
        return $this->hasRoleCached($roles);
    }

    /**
     * Check if user has all specified roles with caching
     */
    public function hasAllRolesCached(array $roles): bool
    {
        $cacheService = app(ReferenceDataCacheService::class);

        foreach ($roles as $role) {
            if (!$cacheService->getUserRoleCache($this, $role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clear this user's permission and role caches
     */
    public function clearPermissionCaches(): void
    {
        $cacheService = app(ReferenceDataCacheService::class);
        $cacheService->clearUserCaches($this->id);
    }

    /**
     * Check if user is privileged (Super Administrator, Admin, etc.) with caching
     */
    public function isPrivilegedUserCached(): bool
    {
        return $this->hasAnyRoleCached([
            'Super Administratoristrator',
            'Administrator',
            'Project Manager',
            'Consultant',
            'HR Manager'
        ]);
    }
}