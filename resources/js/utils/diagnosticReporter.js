/**
 * Web Frontend Diagnostic Telemetry Sink
 *
 * Forwards client-side errors (React render crashes, 422 validation failures,
 * 409 stale-write conflicts, 400 bad requests, and unhandled promise rejections)
 * into the unified Client Diagnostics triage system (/admin/client-errors).
 */

const CLIENT_ERRORS_ENDPOINT = '/api/v1/client-errors';
const DEDUPE_WINDOW_MS = 60000;
const RATE_LIMIT_WINDOW_MS = 60000;
const RATE_LIMIT_MAX_EVENTS = 20;

const seenFingerprints = new Map();
let rateWindowStart = 0;
let rateWindowCount = 0;

// Simple hash for in-memory deduplication
const hashString = (text) => {
    let hash = 2166136261;
    const str = String(text || '');
    for (let i = 0; i < str.length; i++) {
        hash ^= str.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
};

const sanitize = (obj, depth = 0) => {
    if (!obj || typeof obj !== 'object' || depth > 4) return obj;
    if (Array.isArray(obj)) return obj.map(item => sanitize(item, depth + 1));
    const clean = {};
    for (const [key, value] of Object.entries(obj)) {
        if (/password|token|secret|pin|authorization|cookie/i.test(key)) {
            clean[key] = '[REDACTED]';
        } else if (typeof value === 'object') {
            clean[key] = sanitize(value, depth + 1);
        } else {
            clean[key] = value;
        }
    }
    return clean;
};

export const reportWebError = async ({
    message,
    errorType = 'WebError',
    severity = 'error',
    stack = '',
    screen = '',
    context = null,
    statusCode = null,
    httpMethod = null,
    path = null,
} = {}) => {
    try {
        if (typeof window === 'undefined') return false;

        const currentPath = path || window.location.pathname;
        if (currentPath && (currentPath.includes('client-errors') || currentPath.includes('log-error'))) {
            return false; // Prevent recursive loops
        }

        const cleanMsg = String(message || 'Unknown web error').trim().slice(0, 2000);
        const fingerprint = hashString(`${errorType}|${cleanMsg.replace(/\d+/g, '#').slice(0, 200)}|${statusCode || ''}`);
        const now = Date.now();

        // Rate limiting
        if (now - rateWindowStart > RATE_LIMIT_WINDOW_MS) {
            rateWindowStart = now;
            rateWindowCount = 0;
        }
        if (rateWindowCount >= RATE_LIMIT_MAX_EVENTS) {
            return false;
        }

        // Deduplication
        const lastSeen = seenFingerprints.get(fingerprint);
        if (lastSeen && now - lastSeen < DEDUPE_WINDOW_MS) {
            return false;
        }
        seenFingerprints.set(fingerprint, now);
        rateWindowCount++;

        // Clean up stale fingerprints
        if (seenFingerprints.size > 100) {
            for (const [key, ts] of seenFingerprints) {
                if (now - ts > DEDUPE_WINDOW_MS) seenFingerprints.delete(key);
            }
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const event = {
            source: 'web',
            platform: 'web',
            message: cleanMsg,
            error_type: String(errorType).slice(0, 191),
            severity: ['fatal', 'error', 'warning'].includes(severity) ? severity : 'error',
            stack: stack ? String(stack).slice(0, 20000) : null,
            screen: String(screen || window.location.pathname).slice(0, 191),
            path: currentPath ? String(currentPath).slice(0, 255) : null,
            http_method: httpMethod ? String(httpMethod).toUpperCase().slice(0, 10) : null,
            status_code: statusCode ? Number(statusCode) : null,
            context: context ? sanitize(context) : null,
            occurred_at: new Date().toISOString(),
        };

        await fetch(CLIENT_ERRORS_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            body: JSON.stringify({ events: [event] }),
            keepalive: true,
        });

        return true;
    } catch {
        // Never throw from the diagnostic reporter
        return false;
    }
};
