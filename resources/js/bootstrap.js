import axios from 'axios';
import { attachDeviceId, handleDeviceMismatch } from './utils/deviceAuth';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true; // Ensure cookies are sent with requests
window.axios.defaults.withXSRFToken = true;

// Prevent browser/service worker caching of API responses
window.axios.defaults.headers.common['Cache-Control'] = 'no-cache, no-store, must-revalidate';
window.axios.defaults.headers.common['Pragma'] = 'no-cache';
window.axios.defaults.headers.common['Expires'] = '0';

// Attach device ID and cache-busting to all axios requests
axios.interceptors.request.use(
    (config) => {
        try {
            // Add cache-busting timestamp to GET requests
            if (config.method === 'get' || config.method === 'GET') {
                config.params = config.params || {};
                config.params._t = Date.now();
            }
            return attachDeviceId(config);
        } catch (error) {
            console.warn('[Device Auth] Failed to attach device ID:', error);
            // Continue with request even if device ID attachment fails
            return config;
        }
    },
    (error) => Promise.reject(error)
);

// Handle device mismatch errors globally
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const statusCode = Number(error.response?.status || 0);
        const responsePayload = error.response?.data || {};
        const mismatchReason = String(responsePayload.reason || responsePayload.code || '').trim().toLowerCase();

        // Check if error is device verification failure
        if ((statusCode === 401 || statusCode === 403) && mismatchReason === 'invalid_device') {
            handleDeviceMismatch(responsePayload.error || responsePayload.message || 'Your session is no longer valid for this device.');
        }
        return Promise.reject(error);
    }
);