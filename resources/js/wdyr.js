/**
 * Why Did You Render (WDYR) — Development-only re-render diagnostics.
 *
 * MUST be imported BEFORE React in app.jsx (import order matters).
 * Logs to console exactly WHY a component re-rendered:
 *   - "Props changed: data (reference changed but deep-equal)"
 *   - "State changed: count (1 → 2)"
 *
 * This complements React Scan's visual overlay by explaining the root cause.
 *
 * To track a specific component, add:
 *   MyComponent.whyDidYouRender = true;
 *
 * Or set trackAllPureComponents: true below to auto-track all React.memo / PureComponent.
 */
import React from 'react';

if (import.meta.env.DEV) {
    const { default: whyDidYouRender } = await import(
        '@welldone-software/why-did-you-render'
    );

    whyDidYouRender(React, {
        // Track all React.memo and PureComponent instances automatically
        trackAllPureComponents: true,

        // Also track custom hooks (useCallback, useMemo reference changes)
        trackHooks: true,

        // Log to console with expandable diffs
        logOnDifferentValues: true,

        // Highlight updates in the DOM (subtle blue flash)
        hotReloadBufferMs: 500,

        // Exclude noisy framework internals
        exclude: [
            /^Radix/,         // Radix UI primitives
            /^Inertia/,       // Inertia.js internals
            /^QueryClient/,   // React Query internals
            /^Presence/,      // Radix Presence animations
        ],
    });
}
