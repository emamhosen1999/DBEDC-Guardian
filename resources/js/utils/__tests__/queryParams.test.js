import { describe, it, expect } from 'vitest';
import {
    cleanQuery,
    coerceToDefaults,
    hasActiveFilters,
    parseQuery,
    resolveFromUrl,
    stringifyQuery,
    withQuery,
} from '../queryParams';

/**
 * These cover the URL contract that every list page relies on: what goes into
 * the query string, what is deliberately left out, and what comes back when a
 * user refreshes or opens a copied link.
 */

describe('parseQuery', () => {
    it('reads a plain query string', () => {
        expect(parseQuery('?search=rahim&status=active')).toEqual({ search: 'rahim', status: 'active' });
    });

    it('accepts a full URL', () => {
        expect(parseQuery('/users?page=4')).toEqual({ page: '4' });
    });

    it('collects bracketed array params', () => {
        expect(parseQuery('?tags[]=a&tags[]=b')).toEqual({ tags: ['a', 'b'] });
    });

    it('promotes a repeated scalar key to an array rather than dropping a value', () => {
        expect(parseQuery('?id=1&id=2')).toEqual({ id: ['1', '2'] });
    });

    it('returns an empty object for a bare path', () => {
        expect(parseQuery('/users')).toEqual({});
    });

    it('decodes encoded values', () => {
        expect(parseQuery('?search=a%20b%26c')).toEqual({ search: 'a b&c' });
    });
});

describe('cleanQuery', () => {
    const defaults = { search: '', status: 'all', page: 1 };

    it('drops blanks and values equal to their default', () => {
        expect(cleanQuery({ search: '', status: 'all', page: 1 }, defaults)).toEqual({});
    });

    it('keeps values that differ from the default', () => {
        expect(cleanQuery({ search: 'rahim', status: 'all', page: 4 }, defaults)).toEqual({
            search: 'rahim',
            page: 4,
        });
    });

    it('compares loosely so a string page from the URL matches a numeric default', () => {
        expect(cleanQuery({ page: '1' }, defaults)).toEqual({});
    });

    it('drops empty arrays but keeps populated ones', () => {
        expect(cleanQuery({ tags: [] }, {})).toEqual({});
        expect(cleanQuery({ tags: ['a'] }, {})).toEqual({ tags: ['a'] });
    });

    it('treats an array equal to its default as absent', () => {
        expect(cleanQuery({ tags: ['a'] }, { tags: ['a'] })).toEqual({});
    });
});

describe('stringifyQuery', () => {
    it('emits keys in a stable order regardless of input order', () => {
        expect(stringifyQuery({ status: 'open', page: 2 })).toBe(stringifyQuery({ page: 2, status: 'open' }));
    });

    it('serialises arrays in Laravel bracket form', () => {
        expect(stringifyQuery({ tags: ['a', 'b'] })).toBe('tags%5B%5D=a&tags%5B%5D=b');
    });
});

describe('withQuery', () => {
    const defaults = { search: '', status: 'all', page: 1, per_page: 15 };

    it('produces a bare path when nothing differs from the defaults', () => {
        expect(withQuery('/users', { search: '', status: 'all', page: 1 }, defaults)).toBe('/users');
    });

    it('appends only the non-default values', () => {
        expect(withQuery('/users', { search: 'rahim', status: 'active', page: 4 }, defaults)).toBe(
            '/users?page=4&search=rahim&status=active',
        );
    });

    it('discards any query already present on the base path', () => {
        expect(withQuery('/users?stale=1', { page: 2 }, defaults)).toBe('/users?page=2');
    });
});

describe('coerceToDefaults', () => {
    it('restores numbers so pagination arithmetic works after a refresh', () => {
        expect(coerceToDefaults({ page: '4' }, { page: 1 })).toEqual({ page: 4 });
    });

    it('falls back to the default when a number is unparseable', () => {
        expect(coerceToDefaults({ page: 'abc' }, { page: 1 })).toEqual({ page: 1 });
    });

    it('restores booleans from the usual URL spellings', () => {
        expect(coerceToDefaults({ archived: '1' }, { archived: false })).toEqual({ archived: true });
        expect(coerceToDefaults({ archived: 'false' }, { archived: false })).toEqual({ archived: false });
    });

    it('wraps a single value when the default is an array', () => {
        expect(coerceToDefaults({ tags: 'a' }, { tags: [] })).toEqual({ tags: ['a'] });
    });

    it('leaves keys the page does not declare alone', () => {
        expect(coerceToDefaults({ unknown: 'x' }, { page: 1 })).toEqual({ unknown: 'x' });
    });
});

describe('resolveFromUrl', () => {
    const defaults = { search: '', status: 'all', sort: 'created_at', direction: 'desc', page: 1, per_page: 15 };

    it('returns the defaults for a bare URL', () => {
        expect(resolveFromUrl('/incidents', defaults)).toEqual(defaults);
    });

    it('overlays the URL onto the defaults, typed', () => {
        expect(resolveFromUrl('/incidents?severity=critical&page=5', defaults)).toEqual({
            ...defaults,
            severity: 'critical',
            page: 5,
        });
    });

    it('ignores blank params so ?search= does not shadow the default', () => {
        expect(resolveFromUrl('/incidents?search=', defaults).search).toBe('');
    });

    it('round-trips through withQuery — a built URL resolves to the same state', () => {
        const state = { ...defaults, search: 'K27', status: 'offline', page: 3 };
        const url = withQuery('/cameras', state, defaults);

        expect(resolveFromUrl(url, defaults)).toEqual(state);
    });

    it('keeps each page independent — one page URL never resolves another page state', () => {
        const userDefaults = { search: '', role: 'all', page: 1 };
        const cameraDefaults = { search: '', location: 'all', page: 1 };

        const usersUrl = withQuery('/users', { search: 'rahim', role: 'operator', page: 4 }, userDefaults);
        const camerasUrl = withQuery('/cameras', { search: 'K27', location: 'purbachal', page: 2 }, cameraDefaults);

        expect(resolveFromUrl(usersUrl, userDefaults)).toMatchObject({ search: 'rahim', role: 'operator', page: 4 });
        expect(resolveFromUrl(camerasUrl, cameraDefaults)).toMatchObject({
            search: 'K27',
            location: 'purbachal',
            page: 2,
        });
    });
});

describe('hasActiveFilters', () => {
    const defaults = { search: '', status: 'all', page: 1, per_page: 15 };

    it('is false on a pristine page', () => {
        expect(hasActiveFilters(defaults, defaults)).toBe(false);
    });

    it('is false when only pagination has moved', () => {
        expect(hasActiveFilters({ ...defaults, page: 4 }, defaults)).toBe(false);
    });

    it('is true once a real filter is applied', () => {
        expect(hasActiveFilters({ ...defaults, status: 'offline' }, defaults)).toBe(true);
    });

    it('ignores params the page did not declare — a sibling hook\'s tab is not a filter', () => {
        expect(hasActiveFilters({ ...defaults, tab: 'departments' }, defaults)).toBe(false);
    });
});

describe('two hooks sharing one URL', () => {
    it('one hook committing preserves the other hook\'s params', () => {
        // The page-level hook owns `tab`; the list hook owns the filters.
        const listDefaults = { search: '', status: 'all', page: 1 };
        const values = resolveFromUrl('/employees?tab=departments&status=active', listDefaults);

        expect(values.tab).toBe('departments');
        expect(withQuery('/employees', { ...values, status: 'inactive' }, listDefaults)).toBe(
            '/employees?status=inactive&tab=departments',
        );
    });
});
