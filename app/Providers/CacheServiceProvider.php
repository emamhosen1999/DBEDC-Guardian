<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Configure cache store
        $this->configureCacheStore();
    }

    /**
     * Configure cache store with Redis if available
     */
    protected function configureCacheStore(): void
    {
        // Set default cache TTLs for different data types
        Cache::setDefaultCacheTime(3600); // 1 hour default

        // Cache tags for easier invalidation
        $this->registerCacheMacros();
    }

    /**
     * Register cache macros for common patterns
     */
    protected function registerCacheMacros(): void
    {
        Cache::macro('rememberUser', function ($userId, $callback, $ttl = 300) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(['users', "user:{$userId}"])->remember("user:{$userId}", $ttl, $callback);
            }

            return Cache::remember("user:{$userId}", $ttl, $callback);
        });

        Cache::macro('rememberAttendance', function ($date, $callback, $ttl = 300) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(['attendance', "attendance:{$date}"])->remember("attendance:{$date}", $ttl, $callback);
            }

            return Cache::remember("attendance:{$date}", $ttl, $callback);
        });

        Cache::macro('rememberDailyWorks', function ($date, $callback, $ttl = 300) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(['daily-works', "daily-works:{$date}"])->remember("daily-works:{$date}", $ttl, $callback);
            }

            return Cache::remember("daily-works:{$date}", $ttl, $callback);
        });

        Cache::macro('rememberLeaves', function ($userId, $year, $callback, $ttl = 600) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(['leaves', "leaves:user:{$userId}", "leaves:year:{$year}"])->remember("leaves:user:{$userId}:year:{$year}", $ttl, $callback);
            }

            return Cache::remember("leaves:user:{$userId}:year:{$year}", $ttl, $callback);
        });

        Cache::macro('invalidateUser', function ($userId) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(["user:{$userId}"])->flush();
            }

            return Cache::forget("user:{$userId}");
        });

        Cache::macro('invalidateAttendance', function ($date) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(["attendance:{$date}"])->flush();
            }

            return Cache::forget("attendance:{$date}");
        });

        Cache::macro('invalidateDailyWorks', function ($date) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(["daily-works:{$date}"])->flush();
            }

            return Cache::forget("daily-works:{$date}");
        });

        Cache::macro('invalidateLeaves', function ($userId, $year) {
            if (method_exists(Cache::getStore(), 'tags')) {
                return Cache::tags(["leaves:user:{$userId}", "leaves:year:{$year}"])->flush();
            }

            return Cache::forget("leaves:user:{$userId}:year:{$year}");
        });
    }
}
