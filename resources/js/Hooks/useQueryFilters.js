import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { hasActiveFilters, resolveFromUrl, withQuery } from '@/utils/queryParams';

/**
 * Server-driven page state (search, filters, sort, pagination) backed by the URL.
 *
 * ── Why the URL ───────────────────────────────────────────────────────────────
 * Anything that changes *which rows the server returns* lives in the query
 * string. That is the only representation that survives a refresh, can be
 * copied to a colleague, and makes browser Back/Forward mean something. This
 * hook therefore treats `usePage().url` as the single source of truth and never
 * keeps a second copy of those values in React state.
 *
 * The one exception is the "draft" layer: text inputs need to feel instant, so
 * debounced keys (`search` by default) are mirrored in local state while the
 * user types and committed to the URL once they pause.
 *
 * ── Two data archetypes ───────────────────────────────────────────────────────
 * This application fetches list data in two different ways, so the hook
 * supports both:
 *
 *  - `mode: 'server'` — the page receives rows as Inertia props. Committing a
 *    filter issues `router.get()`, the controller re-runs and new props arrive.
 *
 *  - `mode: 'client'` — the page fetches rows itself (react-query / axios).
 *    Committing a filter uses Inertia's *client-side* visit (`router.replace`),
 *    which updates the URL and history entry without a server round trip. The
 *    page feeds `values` into its react-query key, so the query refetches and a
 *    copied URL still reproduces the same dataset.
 *
 * ── No effect loops ───────────────────────────────────────────────────────────
 * Navigation only ever happens from an explicit user action (or the debounce
 * timer). Nothing in here watches props and re-navigates, so there is no
 * `state -> effect -> request -> props -> effect -> request` cycle.
 *
 * @example
 * const f = useQueryFilters({
 *     routeName: 'admin.device-sessions.index',
 *     defaults: { search: '', status: 'all', page: 1, per_page: 15 },
 * });
 * <TextField value={f.draft.search} onChange={e => f.setDraft('search', e.target.value)} />
 * <Select value={f.values.status} onValueChange={v => f.set('status', v)} />
 *
 * @param {Object}   options
 * @param {Object}   options.defaults        Default value for every tracked key. Doubles as
 *                                           the type hint (numbers stay numbers) and as the
 *                                           definition of "unfiltered".
 * @param {string}   [options.routeName]     Ziggy route name. Falls back to the current path.
 * @param {'server'|'client'} [options.mode='server']
 * @param {string[]} [options.debounceKeys=['search']] Keys routed through the draft layer.
 * @param {number}   [options.debounceMs=350]
 * @param {string}   [options.pageKey='page']
 * @param {string[]} [options.only]          Inertia partial-reload prop names (server mode).
 * @param {boolean}  [options.preserveScroll=true]
 */
export function useQueryFilters({
    defaults = {},
    routeName,
    routeParams,
    mode = 'server',
    debounceKeys = ['search'],
    debounceMs = 350,
    pageKey = 'page',
    only,
    preserveScroll = true,
} = {}) {
    const { url } = usePage();

    // Defaults are treated as a constant shape. Holding them in a ref keeps every
    // callback below stable even when a page passes an inline object literal.
    const defaultsRef = useRef(defaults);
    const [values, valuesKey] = useUrlValues(url, defaultsRef.current);

    const valuesRef = useRef(values);
    valuesRef.current = values;

    const [loading, setLoading] = useState(false);

    /* ── the draft layer (debounced text inputs) ─────────────────────────── */

    const [draft, setDraftState] = useState(() => pick(values, debounceKeys));
    const committedRef = useRef(pick(values, debounceKeys));
    const timersRef = useRef({});

    // Adopt the URL's value whenever it changes to something we did not just
    // commit ourselves — i.e. Back/Forward, a reset, or an external link.
    useEffect(() => {
        const next = {};
        let changed = false;

        for (const key of debounceKeys) {
            const fromUrl = values[key] ?? '';
            if (String(fromUrl) !== String(committedRef.current[key] ?? '')) {
                next[key] = fromUrl;
                committedRef.current[key] = fromUrl;
                changed = true;
            }
        }

        if (changed) setDraftState((prev) => ({ ...prev, ...next }));
        // `valuesKey` is a stable string digest of the URL-derived values.
    }, [valuesKey]); // eslint-disable-line react-hooks/exhaustive-deps

    // Cancel any pending debounce when the page unmounts.
    useEffect(() => () => Object.values(timersRef.current).forEach(clearTimeout), []);

    /* ── committing to the URL ───────────────────────────────────────────── */

    // Always path-relative: Ziggy returns absolute URLs, `usePage().url` is a
    // path, and mixing the two would break both comparison and history entries.
    const basePath = useMemo(() => {
        if (routeName && typeof route === 'function') {
            try {
                return toPath(route(routeName, routeParams));
            } catch {
                /* route not registered here — fall through to the current path */
            }
        }
        return toPath(url);
    }, [routeName, routeParams, url]);

    // The canonical spelling of where we are now. Comparing against this — rather
    // than the raw URL — means param order and omitted defaults never register as
    // a change, so no redundant requests are issued.
    const currentUrlRef = useRef('');
    currentUrlRef.current = withQuery(basePath, values, defaultsRef.current);

    /**
     * Write a set of changes into the URL.
     *
     * @param {Object}  changes
     * @param {Object}  [opts]
     * @param {boolean} [opts.resetPage=true]  Filter changes send the user back to page 1.
     * @param {boolean} [opts.replace=true]    Replace the history entry instead of adding one.
     * @param {boolean} [opts.replaceAll=false] Start from this hook's defaults instead of the
     *                                          current URL, dropping every other param. Used
     *                                          when a page-level switch (a tab) should not
     *                                          carry a sibling panel's filters with it.
     */
    const commit = useCallback(
        (changes, { resetPage = true, replace = true, replaceAll = false } = {}) => {
            const merged = replaceAll
                ? { ...defaultsRef.current, ...changes }
                : { ...valuesRef.current, ...changes };

            // Only a hook that owns pagination resets it — a page-level hook that
            // just tracks `tab` must not write `page=1` into a sibling's URL.
            if (resetPage && !(pageKey in changes) && pageKey in defaultsRef.current) {
                merged[pageKey] = defaultsRef.current[pageKey];
            }

            const target = withQuery(basePath, merged, defaultsRef.current);

            // Nothing to do if the URL would not actually change. This is what
            // stops a re-render or a redundant select change from firing a request.
            if (target === currentUrlRef.current) return;

            // The new URL arrives asynchronously, so a second commit in the same
            // tick would otherwise read the values from before the first one and
            // undo it. Build on what we just asked for instead.
            valuesRef.current = merged;
            currentUrlRef.current = target;

            if (mode === 'client') {
                // Client-side visit: updates the URL and history, no server request.
                router[replace ? 'replace' : 'push']({
                    url: target,
                    preserveState: true,
                    preserveScroll,
                });
                return;
            }

            router.get(
                target,
                {},
                {
                    preserveState: true,
                    preserveScroll,
                    replace,
                    ...(only ? { only } : {}),
                    onStart: () => setLoading(true),
                    onFinish: () => setLoading(false),
                },
            );
        },
        [basePath, mode, only, pageKey, preserveScroll],
    );

    /* ── public setters ──────────────────────────────────────────────────── */

    /** Update a debounced input. The URL follows once the user stops typing. */
    const setDraft = useCallback(
        (key, value) => {
            setDraftState((prev) => ({ ...prev, [key]: value }));

            clearTimeout(timersRef.current[key]);
            timersRef.current[key] = setTimeout(() => {
                committedRef.current[key] = value;
                commit({ [key]: value });
            }, debounceMs);
        },
        [commit, debounceMs],
    );

    /** Set one filter immediately (selects, chips, toggles). Resets to page 1. */
    const set = useCallback((key, value) => commit({ [key]: value }), [commit]);

    /** Set several filters at once. Resets to page 1. */
    const setMany = useCallback((changes) => commit(changes), [commit]);

    /**
     * Change page. Pagination gets a real history entry so Back returns to the
     * previous page of results, which is what users expect from a page control.
     */
    const setPage = useCallback(
        (page) => commit({ [pageKey]: page }, { resetPage: false, replace: false }),
        [commit, pageKey],
    );

    /** Change page size; row counts shift, so return to page 1. */
    const setPerPage = useCallback((perPage) => commit({ per_page: perPage }), [commit]);

    /**
     * Sort by a column. Clicking the active column flips the direction;
     * a different column starts ascending. Sorting reshuffles the result set,
     * so it returns to page 1.
     */
    const setSort = useCallback(
        (column, direction) => {
            const current = valuesRef.current;
            const nextDirection =
                direction ?? (current.sort === column && current.direction === 'asc' ? 'desc' : 'asc');

            return commit({ sort: column, direction: nextDirection });
        },
        [commit],
    );

    /**
     * Clear every server-driven filter back to the page defaults.
     *
     * This deliberately touches only URL state — remembered UI preferences such
     * as view mode or column visibility are left alone.
     */
    const reset = useCallback(() => {
        Object.values(timersRef.current).forEach(clearTimeout);

        const cleared = { ...defaultsRef.current };
        for (const key of debounceKeys) committedRef.current[key] = cleared[key] ?? '';
        setDraftState(pick(cleared, debounceKeys));

        commit(cleared, { resetPage: true });
    }, [commit, debounceKeys]);

    return {
        /** Effective, typed state as encoded in the URL. The source of truth. */
        values,
        /** Working copy of debounced inputs — bind text fields to this. */
        draft,
        setDraft,
        set,
        setMany,
        setPage,
        setPerPage,
        setSort,
        reset,
        /** True when any non-pagination filter differs from its default. */
        isFiltered: hasActiveFilters(values, defaultsRef.current, [pageKey, 'per_page']),
        /** True while a server-mode visit is in flight. */
        loading,
        /** Escape hatch for pages that need a bespoke transition. */
        commit,
    };
}

/**
 * Snap an out-of-range page back to the last real page.
 *
 * A remembered or hand-edited `?page=17` can outlive the data — rows were
 * deleted, a filter narrowed the set — and Laravel answers such a request with
 * an empty page rather than an error. Once the server has told us how many
 * pages exist, move to the last one instead of showing "no results" on a page
 * that no longer exists. The URL is replaced, not pushed, so Back still works.
 *
 * Call it after the data is available, since the page count comes from it:
 *
 * @example
 * const f = useQueryFilters({ defaults: { page: 1, ... } });
 * const { data } = useRowsQuery(f.values);
 * useClampPage(f, data?.last_page);
 *
 * @param {ReturnType<typeof useQueryFilters>} filters
 * @param {number|null|undefined} lastPage  Page count from the server; skipped while unknown.
 * @param {string} [pageKey='page']
 */
export function useClampPage(filters, lastPage, pageKey = 'page') {
    const page = filters.values[pageKey];
    const commit = filters.commit;

    useEffect(() => {
        if (lastPage === null || lastPage === undefined) return;

        const last = Math.max(1, Number(lastPage) || 1);

        if (typeof page === 'number' && page > last) {
            commit({ [pageKey]: last }, { resetPage: false, replace: true });
        }
    }, [page, lastPage, pageKey, commit]);
}

/** Derive typed values from the URL, memoised on a stable digest. */
function useUrlValues(url, defaults) {
    const values = useMemo(() => resolveFromUrl(url, defaults), [url, defaults]);
    const valuesKey = useMemo(() => JSON.stringify(values), [values]);

    return [values, valuesKey];
}

/** Reduce an absolute or relative URL to a leading-slash path, dropping any query. */
function toPath(value) {
    const raw = String(value ?? '').split('?')[0];
    const withoutOrigin = raw.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]+/i, '');

    return withoutOrigin.startsWith('/') ? withoutOrigin : `/${withoutOrigin}`;
}

function pick(source, keys) {
    const out = {};
    for (const key of keys) out[key] = source[key] ?? '';
    return out;
}

export default useQueryFilters;
