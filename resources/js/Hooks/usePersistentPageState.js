import { useCallback, useMemo, useRef } from 'react';
import { useRemember, usePage } from '@inertiajs/react';

/** Bump when the shape of remembered state changes incompatibly across the app. */
const STATE_VERSION = 'v1';

/**
 * Local UI state that survives Inertia navigation and browser Back/Forward.
 *
 * This is for presentation-only state — the kind that does not change which
 * rows the server returns and does not need to be shareable or bookmarkable.
 * Anything that *does* affect server data belongs in the URL; use
 * `useQueryFilters()` for that instead.
 *
 * Good candidates:
 *   view mode, table density, column visibility, expanded row ids,
 *   collapsed panels, a purely presentational tab, inspector/drawer open state.
 *
 * Never put in here:
 *   server payloads or full row objects (store ids and re-derive),
 *   passwords, tokens, keys or anything else sensitive,
 *   high-frequency values (pointer position, drag offsets, resize deltas).
 *
 * Remembered state is written into the browser's history entry, so it is capped
 * in size by the History API and is readable by anyone with the device. Keep it
 * small and keep it boring.
 *
 * @example
 * const [ui, setUi, resetUi] = usePersistentPageState('Cameras/Index', {
 *     viewMode: 'table',
 *     expandedRows: [],
 * });
 * setUi({ viewMode: 'grid' });
 * setUi(prev => ({ expandedRows: [...prev.expandedRows, id] }));
 *
 * @param {string} pageKey    Stable page identifier, e.g. `'Employees/Index'`.
 *                            Include the record id on entity pages so two
 *                            records do not share state: `'Users/Edit:42'`.
 * @param {Object} defaults   Default shape. Keys added here later are merged
 *                            into stale remembered state automatically.
 * @param {Object} [options]
 * @param {string[]} [options.excludeKeys]  Keys held in state but never written
 *                                          to history (e.g. transient drafts).
 * @param {boolean}  [options.scopeToUser=true] Namespace the key by the signed-in
 *                                          user so state cannot bleed across
 *                                          accounts on a shared browser.
 * @returns {[Object, Function, Function]} `[state, setState, resetState]`
 */
export function usePersistentPageState(pageKey, defaults, options = {}) {
    const { excludeKeys, scopeToUser = true } = options;
    const page = usePage();

    // Defaults define the shape, not a reactive input. Pinning them in a ref lets
    // pages pass an inline object literal without destabilising the callbacks.
    const defaultsRef = useRef(defaults);

    const userId = scopeToUser
        ? page?.props?.auth?.user?.id ?? page?.props?.auth?.user?.employee_id ?? null
        : null;

    const stateKey = userId
        ? `ui:${STATE_VERSION}:user:${userId}:${pageKey}`
        : `ui:${STATE_VERSION}:${pageKey}`;

    const excludeRef = useRef(excludeKeys);
    excludeRef.current = excludeKeys;

    const [raw, setRaw] = useRemember(defaultsRef.current, stateKey, excludeKeys ? excludeRef : undefined);

    // Merge over the current defaults so state remembered by an older build —
    // which will not contain keys added since — still reads correctly today.
    // Memoised so `ui` keeps a stable identity and can safely sit in dependency
    // arrays without re-triggering effects on every render.
    const state = useMemo(() => ({ ...defaultsRef.current, ...raw }), [raw]);

    /**
     * Merge a partial update into the remembered state.
     * Accepts an object or an updater receiving the previous (merged) state.
     */
    const setState = useCallback(
        (updater) => {
            setRaw((prev) => {
                const merged = { ...defaultsRef.current, ...prev };
                const patch = typeof updater === 'function' ? updater(merged) : updater;

                if (!patch) return merged;

                const next = { ...merged, ...patch };

                // Skip the write when nothing actually changed. Returning the
                // original reference lets React bail out of the re-render, which
                // also keeps `state` below referentially stable — every set()
                // otherwise costs a history write and a render.
                return shallowEqual(merged, next) ? prev : next;
            });
        },
        [setRaw],
    );

    /** Restore every key to its default. Does not touch URL state. */
    const resetState = useCallback(() => setRaw({ ...defaultsRef.current }), [setRaw]);

    return [state, setState, resetState];
}

function shallowEqual(a, b) {
    const keys = Object.keys(b);

    if (Object.keys(a).length !== keys.length) return false;

    return keys.every((key) => Object.is(a[key], b[key]));
}

export default usePersistentPageState;
