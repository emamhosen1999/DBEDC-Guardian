/**
 * Canonical query-string helpers for URL-backed page state.
 *
 * These back the `useQueryFilters()` hook and exist so that every page in the
 * application serialises server-driven state (search / filters / sort /
 * pagination) into the URL the same way.
 *
 * Rules enforced here:
 *  - A value that equals its default is omitted from the URL, so the "clean"
 *    state of a page is a bare path and "Reset filters" produces a tidy URL.
 *  - Empty strings, null and undefined are never written.
 *  - Arrays serialise as repeated `key[]=` params, matching Laravel's parser.
 *  - Keys are emitted in a stable order so the same state always produces the
 *    same URL (important for react-query cache keys and for tests).
 */

/**
 * Isolate the query portion of an input that may be a full URL, a path, a
 * search string, or nothing at all.
 *
 * Without this, a bare path like `/users` would be handed to URLSearchParams
 * and come back as the bogus param `{ '/users': '' }`.
 */
function extractSearch(input) {
    const value = String(input ?? '');

    if (value.includes('?')) return value.slice(value.indexOf('?') + 1);

    // No '?' — this is only a query string if it looks like one.
    if (!value.includes('=')) return '';
    if (value.startsWith('/') || value.includes('://')) return '';

    return value;
}

/** Values that are never written to the URL. */
const isBlank = (value) =>
    value === '' || value === null || value === undefined || (Array.isArray(value) && value.length === 0);

/**
 * Parse a query string (or a full URL) into a plain object.
 *
 * `?tags[]=a&tags[]=b` and `?tags=a&tags=b` both yield `{ tags: ['a', 'b'] }`.
 *
 * @param {string} input  A search string, or any URL containing one.
 * @returns {Object<string, string|string[]>}
 */
export function parseQuery(input = '') {
    const params = new URLSearchParams(extractSearch(input));
    const out = {};

    for (const [rawKey, value] of params.entries()) {
        const key = rawKey.endsWith('[]') ? rawKey.slice(0, -2) : rawKey;

        if (rawKey.endsWith('[]')) {
            out[key] = [...(out[key] ?? []), value];
        } else if (key in out) {
            // Repeated scalar key — promote to an array rather than losing data.
            out[key] = [...[].concat(out[key]), value];
        } else {
            out[key] = value;
        }
    }

    return out;
}

/**
 * Strip values that should not appear in the URL.
 *
 * A value is dropped when it is blank, or when it is identical to the page's
 * declared default for that key. That keeps URLs short and makes "is this page
 * filtered?" a simple emptiness check.
 *
 * @param {Object} values    Candidate query values.
 * @param {Object} defaults  The page's default values.
 * @returns {Object} Cleaned values, key-sorted for stability.
 */
export function cleanQuery(values = {}, defaults = {}) {
    const out = {};

    for (const key of Object.keys(values).sort()) {
        const value = values[key];

        if (isBlank(value)) continue;
        if (key in defaults && isSameValue(value, defaults[key])) continue;

        out[key] = value;
    }

    return out;
}

/** Loose equality that treats `1` and `'1'` alike, since URLs are always strings. */
function isSameValue(a, b) {
    if (Array.isArray(a) || Array.isArray(b)) {
        const left = [].concat(a ?? []);
        const right = [].concat(b ?? []);
        return left.length === right.length && left.every((v, i) => String(v) === String(right[i]));
    }

    return String(a) === String(b);
}

/**
 * Serialise values into a query string (without the leading `?`).
 *
 * @param {Object} values  Already-cleaned values.
 * @returns {string}
 */
export function stringifyQuery(values = {}) {
    const params = new URLSearchParams();

    for (const key of Object.keys(values).sort()) {
        const value = values[key];

        if (Array.isArray(value)) {
            value.forEach((entry) => {
                if (!isBlank(entry)) params.append(`${key}[]`, String(entry));
            });
            continue;
        }

        if (!isBlank(value)) params.append(key, String(value));
    }

    return params.toString();
}

/**
 * Build a full URL from a path and a set of query values.
 *
 * @param {string} pathname  Path (or absolute URL) to build on.
 * @param {Object} values    Query values; blank/default entries are dropped.
 * @param {Object} defaults  The page's default values.
 * @returns {string} e.g. `/cameras?page=3&status=offline`
 */
export function withQuery(pathname, values = {}, defaults = {}) {
    const path = String(pathname).split('?')[0];
    const query = stringifyQuery(cleanQuery(values, defaults));

    return query ? `${path}?${query}` : path;
}

/**
 * Coerce URL strings back into the types implied by the page defaults, so that
 * `?page=4` becomes the number `4` when the default is a number, and
 * `?archived=1` becomes a boolean when the default is a boolean.
 *
 * Unknown keys are passed through untouched.
 *
 * @param {Object} values    Raw values parsed from the URL.
 * @param {Object} defaults  The page's default values.
 * @returns {Object}
 */
export function coerceToDefaults(values = {}, defaults = {}) {
    const out = { ...values };

    for (const [key, fallback] of Object.entries(defaults)) {
        if (!(key in out)) continue;

        const raw = out[key];

        if (typeof fallback === 'number') {
            const parsed = Number(raw);
            out[key] = Number.isFinite(parsed) ? parsed : fallback;
        } else if (typeof fallback === 'boolean') {
            out[key] = raw === true || raw === 'true' || raw === '1' || raw === 1;
        } else if (Array.isArray(fallback)) {
            out[key] = [].concat(raw);
        }
    }

    return out;
}

/**
 * Resolve the effective, typed state of a page from its URL.
 *
 * This is the single source of truth used by `useQueryFilters()`.
 *
 * @param {string} url       Current URL (from Inertia's `usePage().url`).
 * @param {Object} defaults  The page's default values.
 * @returns {Object} Defaults merged with (and overridden by) the URL.
 */
export function resolveFromUrl(url, defaults = {}) {
    const parsed = coerceToDefaults(parseQuery(url), defaults);
    const out = { ...defaults };

    for (const [key, value] of Object.entries(parsed)) {
        if (!isBlank(value)) out[key] = value;
    }

    return out;
}

/**
 * Whether any non-default filter is currently applied.
 *
 * Pagination keys are ignored by default: being on page 3 is not "filtered".
 *
 * @param {Object} values
 * @param {Object} defaults
 * @param {string[]} [ignoreKeys]
 * @returns {boolean}
 */
export function hasActiveFilters(values = {}, defaults = {}, ignoreKeys = ['page', 'per_page']) {
    // Only keys this page declares count. A page can carry other params in its
    // URL (a sibling hook's `tab`, say) that are not this page's filters.
    return Object.keys(cleanQuery(values, defaults)).some(
        (key) => key in defaults && !ignoreKeys.includes(key),
    );
}
