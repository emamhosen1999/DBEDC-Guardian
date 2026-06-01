// Service Worker Emergency Reset
// Use this to completely reset service workers and caches in case of infinite reload issues

export const emergencyServiceWorkerReset = async () => {
    
    try {
        // 1. Unregister all service workers
        if ('serviceWorker' in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            
            for (const registration of registrations) {
                await registration.unregister();
            }
        }
        
        // 2. Clear all caches
        if ('caches' in window) {
            const cacheNames = await caches.keys();
            
            for (const cacheName of cacheNames) {
                await caches.delete(cacheName);
            }
        }
        
        // 3. Clear relevant localStorage items (including translations)
        const itemsToRemove = [
            'app_version',
            'app_version_timestamp',
            'app_version_last_check',
            'sw_version',
            'sw_update_available',
            'translations_cache_v4',
            'translations_cache_v5',
            'glassERP_performance_baseline',
        ];
        
        itemsToRemove.forEach(item => {
            if (localStorage.getItem(item)) {
                localStorage.removeItem(item);
            }
        });
        

        return {
            success: true,
            message: 'Service workers and caches cleared successfully'
        };
        
    } catch (error) {
        console.error('❌ Emergency reset failed:', error);
        return {
            success: false,
            error: error.message
        };
    }
};

/**
 * Clear all application caches without unregistering service worker
 * Use this to force fresh data without full reset
 */
export const clearAllCaches = async () => {
    
    try {
        // Clear browser caches
        if ('caches' in window) {
            const cacheNames = await caches.keys();
            for (const cacheName of cacheNames) {
                await caches.delete(cacheName);
            }
        }
        // Clear translation cache
        const translationKeys = [
            'translations_cache_v4',
            'translations_cache_v5',
        ];
        translationKeys.forEach(key => {
            if (localStorage.getItem(key)) {
                localStorage.removeItem(key);
            }
        });
        
        // Clear performance data
        if (localStorage.getItem('glassERP_performance_baseline')) {
            localStorage.removeItem('glassERP_performance_baseline');
        }
        

        return { success: true };
        
    } catch (error) {
        console.error('❌ Failed to clear caches:', error);
        return { success: false, error: error.message };
    }
};

// Auto-execute if called directly from console
if (typeof window !== 'undefined') {
    window.emergencyServiceWorkerReset = emergencyServiceWorkerReset;
    window.clearAllCaches = clearAllCaches;
}

export default emergencyServiceWorkerReset;
