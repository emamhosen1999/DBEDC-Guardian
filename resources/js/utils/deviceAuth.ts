/**
 * Secure Device Authentication Utility
 * Generates and manages UUIDv4 device identifiers for single-device login enforcement
 */

import { v4 as uuidv4 } from 'uuid';

const DEVICE_ID_KEY = 'aero_device_id';

const normalize = (value: unknown): string => {
    return String(value ?? '').trim();
};

const quickStableHash = (value: string): string => {
    const source = normalize(value);
    let hash = 2166136261;

    for (let index = 0; index < source.length; index += 1) {
        hash ^= source.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }

    return `fnv1a-${(hash >>> 0).toString(16).padStart(8, '0')}`;
};

const getBrowserName = (): string => {
    if (typeof navigator === 'undefined') {
        return 'Web Browser';
    }

    const userAgent = normalize(navigator.userAgent).toLowerCase();

    if (userAgent.includes('edg/')) {
        return 'Edge';
    }

    if (userAgent.includes('firefox/')) {
        return 'Firefox';
    }

    if (userAgent.includes('safari/') && !userAgent.includes('chrome/')) {
        return 'Safari';
    }

    if (userAgent.includes('chrome/')) {
        return 'Chrome';
    }

    return 'Web Browser';
};

export function getWebDeviceName(): string {
    const browser = getBrowserName();

    if (typeof navigator === 'undefined') {
        return `${browser} on Web`;
    }

    const platform = normalize(navigator.platform || 'Web');
    return `${browser} on ${platform}`;
}

export function getWebDeviceSignature(): Record<string, string> {
    const browser = getBrowserName();

    if (typeof window === 'undefined' || typeof navigator === 'undefined') {
        return {
            signature: '',
            platform: 'web',
            os_version: '',
            model: browser,
            manufacturer: 'Web Browser',
            brand: browser,
            hardware_id: '',
            app_version: '',
            build_version: '',
            mac_address: '',
        };
    }

    const timezone = normalize(Intl.DateTimeFormat().resolvedOptions().timeZone || '');
    const screenResolution = `${window.screen?.width || 0}x${window.screen?.height || 0}`;
    const browserLanguage = normalize(navigator.language || '');
    const platformName = normalize(navigator.platform || 'Web');

    const hardwareSeed = [
        getDeviceId(),
        normalize(navigator.userAgent),
        browserLanguage,
        platformName,
        normalize((navigator as any).hardwareConcurrency),
        normalize((navigator as any).deviceMemory),
        timezone,
        screenResolution,
    ].join('|');

    const signatureSeed = [
        browser,
        normalize(navigator.userAgent),
        browserLanguage,
        platformName,
        timezone,
        screenResolution,
    ].join('|');

    return {
        signature: quickStableHash(signatureSeed),
        platform: 'web',
        os_version: platformName,
        model: browser,
        manufacturer: 'Web Browser',
        brand: browser,
        hardware_id: quickStableHash(hardwareSeed),
        app_version: normalize((import.meta as any).env?.VITE_APP_VERSION || ''),
        build_version: normalize((import.meta as any).env?.VITE_APP_BUILD || ''),
        mac_address: '',
    };
}

export function getDeviceLoginPayload(): Record<string, unknown> {
    return {
        device_id: getDeviceId(),
        device_name: getWebDeviceName(),
        device_signature: getWebDeviceSignature(),
    };
}

/**
 * Get or generate a persistent device ID (UUIDv4).
 * This ID is stored in localStorage and uniquely identifies this browser/device.
 *
 * @returns {string} UUIDv4 device identifier
 */
export function getDeviceId(): string {
    // Try to retrieve existing device ID from localStorage
    let deviceId = localStorage.getItem(DEVICE_ID_KEY);

    // If no device ID exists, generate a new UUIDv4
    if (!deviceId) {
        deviceId = uuidv4();
        localStorage.setItem(DEVICE_ID_KEY, deviceId);
 
    }

    return deviceId;
}

/**
 * Clear the stored device ID.
 * USE WITH CAUTION: This will force the user to re-register on next login.
 */
export function clearDeviceId(): void {
    localStorage.removeItem(DEVICE_ID_KEY);

}

/**
 * Check if device ID exists in localStorage.
 *
 * @returns {boolean}
 */
export function hasDeviceId(): boolean {
    return localStorage.getItem(DEVICE_ID_KEY) !== null;
}

/**
 * Get device ID for including in request headers.
 *
 * @returns {Record<string, string>}
 */
export function getDeviceHeaders(): Record<string, string> {
    const signature = getWebDeviceSignature();

    return {
        'X-Device-ID': getDeviceId(),
        'X-Device-Signature': signature.signature,
        'X-Client-Type': 'web',
    };
}

/**
 * Attach device ID to axios request config.
 * Used as an axios interceptor.
 *
 * @param {any} config - Axios request config
 * @returns {any} Modified config with device ID header
 */
export function attachDeviceId(config: any): any {
    const headers = getDeviceHeaders();

    config.headers = {
        ...config.headers,
        ...headers,
    };

    return config;
}

/**
 * Handle device mismatch errors.
 * Called when the backend returns a 403 with device_mismatch reason.
 *
 * @param {string} message - Error message from backend
 */
export function handleDeviceMismatch(message: string): void {
    console.error('[Device Auth] Device mismatch detected:', message);

    // Show alert to user
    alert(
        message || 
        'Device mismatch. Account is locked to another device. Please contact your administrator to reset device access.'
    );

    // Clear localStorage
    clearDeviceId();

    // Redirect to login
    window.location.href = '/login';
}

/**
 * Handle missing localStorage scenario.
 * Some browsers in incognito/private mode may block localStorage.
 *
 * @returns {boolean} True if localStorage is available
 */
export function checkLocalStorageAvailability(): boolean {
    try {
        const test = '__localStorage_test__';
        localStorage.setItem(test, test);
        localStorage.removeItem(test);
        return true;
    } catch (e) {
        console.error('[Device Auth] localStorage is not available:', e);
        return false;
    }
}

/**
 * Initialize device authentication on app load.
 * Checks localStorage availability and generates device ID if needed.
 */
export function initializeDeviceAuth(): void {
    if (!checkLocalStorageAvailability()) {
        console.warn('[Device Auth] localStorage unavailable - device binding may not work');
        return;
    }

    // Ensure device ID is generated
    getDeviceId();
    getWebDeviceSignature();
   
}

export default {
    getDeviceId,
    getWebDeviceName,
    getWebDeviceSignature,
    getDeviceLoginPayload,
    clearDeviceId,
    hasDeviceId,
    getDeviceHeaders,
    attachDeviceId,
    handleDeviceMismatch,
    checkLocalStorageAvailability,
    initializeDeviceAuth,
};
