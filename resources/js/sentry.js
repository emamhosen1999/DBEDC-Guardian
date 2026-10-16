/**
 * Sentry SDK initialization with Session Replay + Performance Monitoring.
 *
 * Loaded BEFORE React renders so Sentry captures the full page lifecycle.
 * Gated behind VITE_SENTRY_DSN — if the env var is empty, Sentry is
 * completely inert and adds zero runtime overhead.
 *
 * Session Replay records DOM + console + network on errors automatically,
 * giving full reproduction context for every crash the ErrorBoundary catches.
 */
import * as Sentry from '@sentry/react';

const SENTRY_DSN = import.meta.env.VITE_SENTRY_DSN || '';
const IS_PROD = import.meta.env.PROD;

if (SENTRY_DSN) {
    Sentry.init({
        dsn: SENTRY_DSN,
        environment: IS_PROD ? 'production' : 'development',

        // ─── Performance Monitoring ───
        // Captures page load, navigation, and API call durations.
        // 10% of transactions in prod, 100% in dev for full visibility.
        tracesSampleRate: IS_PROD ? 0.1 : 1.0,

        // ─── Session Replay ───
        // Records DOM mutations, console logs, and network waterfall.
        // On error: always capture a replay. Otherwise: sample 10% in prod.
        replaysSessionSampleRate: IS_PROD ? 0.1 : 1.0,
        replaysOnErrorSampleRate: 1.0,

        integrations: [
            // Session Replay — captures what the user saw and did
            Sentry.replayIntegration({
                // Mask all text and block all media in prod for privacy
                maskAllText: IS_PROD,
                blockAllMedia: IS_PROD,
                // Capture network request/response bodies for debugging
                networkDetailAllowUrls: [
                    window.location.origin,
                    /firebase.*\.com/,
                ],
            }),

            // Browser tracing for performance spans
            Sentry.browserTracingIntegration({
                // Inertia SPA navigations are already handled by router events
                enableInp: true,
            }),

            // React-specific: capture component tree in errors
            Sentry.reactRouterV6BrowserTracingIntegration
                ? undefined // Only if using React Router (we use Inertia)
                : undefined,
        ].filter(Boolean),

        // ─── Filtering ───
        // Ignore noisy errors that aren't actionable
        ignoreErrors: [
            // Browser extensions
            'top.GLOBALS',
            'fb_xd_fragment',
            // Network errors
            'Network request failed',
            'Failed to fetch',
            'Load failed',
            // Inertia asset version mismatch (handled by interceptor)
            'NavigationCancelled',
            // Chunk loading (handled by error event listener)
            /Loading chunk .* failed/,
            /Loading CSS chunk .* failed/,
        ],

        // Don't send events from browser extensions
        denyUrls: [
            /extensions\//i,
            /^chrome:\/\//i,
            /^moz-extension:\/\//i,
        ],

        // ─── Before-send hook ───
        // Enrich events with DBEDC-specific context
        beforeSend(event) {
            // Attach device ID if available
            try {
                const deviceId = localStorage.getItem('device_id');
                if (deviceId) {
                    event.tags = { ...event.tags, device_id: deviceId };
                }
            } catch {
                // localStorage unavailable
            }
            return event;
        },
    });

    // Set the user context from Inertia shared props (when available)
    // This is called after Inertia initializes, so it's deferred
    const setUserFromInertia = () => {
        try {
            const pageProps = document.querySelector('[data-page]');
            if (pageProps) {
                const data = JSON.parse(pageProps.getAttribute('data-page'));
                const user = data?.props?.auth?.user;
                if (user) {
                    Sentry.setUser({
                        id: user.id,
                        email: user.email,
                        username: user.name,
                    });
                }
            }
        } catch {
            // Inertia props not available yet
        }
    };

    // Try immediately and also after DOM is ready
    setUserFromInertia();
    if (document.readyState !== 'complete') {
        window.addEventListener('load', setUserFromInertia, { once: true });
    }
}

// Re-export Sentry for use in ErrorBoundary and other components
export { Sentry, SENTRY_DSN };
