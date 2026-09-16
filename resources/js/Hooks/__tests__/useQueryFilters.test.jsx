// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioural tests for the URL-backed page state hook.
 *
 * Inertia is mocked so we can watch exactly which navigations the hook issues
 * and what URL each one targets — that is the contract the acceptance criteria
 * are written against (refresh, copied links, Back/Forward, reset).
 */

let currentUrl = '/users';
const visits = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: currentUrl, props: {} }),
    router: {
        get: (url, data, options) => visits.push({ kind: 'get', url, ...options }),
        replace: (params) => visits.push({ kind: 'replace', ...params }),
        push: (params) => visits.push({ kind: 'push', ...params }),
    },
}));

const { useQueryFilters, useClampPage } = await import('../useQueryFilters');

/** Mount the hook and return a live handle to its latest return value. */
function mount(options) {
    const handle = { current: null };
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);

    function Probe() {
        handle.current = useQueryFilters(options);
        return null;
    }

    act(() => root.render(<Probe />));

    return {
        handle,
        rerender: () => act(() => root.render(<Probe />)),
        unmount: () => act(() => root.unmount()),
    };
}

/** Simulate Inertia landing on a new URL (a visit completing, or Back/Forward). */
function navigateTo(url, harness) {
    currentUrl = url;
    harness.rerender();
}

const DEFAULTS = { search: '', status: 'all', sort: 'name', direction: 'asc', page: 1, per_page: 15 };

beforeEach(() => {
    currentUrl = '/users';
    visits.length = 0;
    vi.useRealTimers();
});

describe('useQueryFilters — reading state', () => {
    it('starts from the page defaults on a bare URL', () => {
        const { handle } = mount({ defaults: DEFAULTS });

        expect(handle.current.values).toEqual(DEFAULTS);
        expect(handle.current.isFiltered).toBe(false);
    });

    it('reads state straight out of the URL, typed — this is what makes refresh work', () => {
        currentUrl = '/users?search=rahim&status=active&page=4';
        const { handle } = mount({ defaults: DEFAULTS });

        expect(handle.current.values.search).toBe('rahim');
        expect(handle.current.values.status).toBe('active');
        expect(handle.current.values.page).toBe(4);
        expect(handle.current.isFiltered).toBe(true);
    });

    it('issues no navigation on mount, so landing on a page costs one request', () => {
        currentUrl = '/users?search=rahim';
        mount({ defaults: DEFAULTS });

        expect(visits).toHaveLength(0);
    });
});

describe('useQueryFilters — committing changes', () => {
    it('writes a filter to the URL and returns to page 1', () => {
        currentUrl = '/users?page=4';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.set('status', 'active'));

        expect(visits).toHaveLength(1);
        expect(visits[0].url).toBe('/users?status=active');
        expect(visits[0].replace).toBe(true);
    });

    it('keeps every other active filter when one changes', () => {
        currentUrl = '/users?search=rahim&status=active';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.set('status', 'inactive'));

        expect(visits[0].url).toBe('/users?search=rahim&status=inactive');
    });

    it('does not reset the page when only the page changes, and adds a history entry', () => {
        currentUrl = '/users?status=active';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.setPage(3));

        expect(visits[0].url).toBe('/users?page=3&status=active');
        expect(visits[0].replace).toBe(false);
    });

    it('skips a commit that would not change the URL, so no redundant request is made', () => {
        currentUrl = '/users?status=active';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.set('status', 'active'));

        expect(visits).toHaveLength(0);
    });

    it('drops a filter from the URL once it returns to its default', () => {
        currentUrl = '/users?status=active';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.set('status', 'all'));

        expect(visits[0].url).toBe('/users');
    });

    it('two commits in the same tick build on each other rather than the second undoing the first', () => {
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => {
            handle.current.set('status', 'active');
            handle.current.set('sort', 'email');
        });

        expect(visits).toHaveLength(2);
        expect(visits[1].url).toBe('/users?sort=email&status=active');
    });

    it('changing page size returns to page 1', () => {
        currentUrl = '/users?page=6';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.setPerPage(50));

        expect(visits[0].url).toBe('/users?per_page=50');
    });
});

describe('useQueryFilters — sorting', () => {
    it('sorts ascending on a new column and resets the page', () => {
        currentUrl = '/users?page=3';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.setSort('status'));

        // `direction=asc` is this page's default, so it is left out of the URL;
        // the page still resolves direction to 'asc' from its defaults.
        expect(visits[0].url).toBe('/users?sort=status');
    });

    it('flips direction when the active column is clicked again', () => {
        currentUrl = '/users?sort=status&direction=asc';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.setSort('status'));

        expect(visits[0].url).toBe('/users?direction=desc&sort=status');
    });
});

describe('useQueryFilters — debounced search', () => {
    it('types without navigating, then commits once', async () => {
        vi.useFakeTimers();
        const { handle } = mount({ defaults: DEFAULTS, debounceMs: 300 });

        act(() => handle.current.setDraft('search', 'r'));
        act(() => handle.current.setDraft('search', 'ra'));
        act(() => handle.current.setDraft('search', 'rahim'));

        expect(visits).toHaveLength(0);
        expect(handle.current.draft.search).toBe('rahim');

        await act(async () => { vi.advanceTimersByTime(300); });

        expect(visits).toHaveLength(1);
        expect(visits[0].url).toBe('/users?search=rahim');
        expect(visits[0].replace).toBe(true);
    });
});

describe('useQueryFilters — restoration', () => {
    it('adopts the URL after Back, so the search box shows the historical value', () => {
        currentUrl = '/users?search=rahim';
        const harness = mount({ defaults: DEFAULTS });

        expect(harness.handle.current.draft.search).toBe('rahim');

        navigateTo('/users?search=karim', harness);

        expect(harness.handle.current.draft.search).toBe('karim');
        expect(harness.handle.current.values.search).toBe('karim');
        expect(visits).toHaveLength(0);
    });

    it('two pages keep independent state — neither URL leaks into the other', () => {
        currentUrl = '/users?search=rahim&status=active&page=4';
        const users = mount({ defaults: DEFAULTS });

        currentUrl = '/cameras?search=K27&status=offline&page=2';
        const cameras = mount({ defaults: { search: '', status: 'all', page: 1 } });

        expect(cameras.handle.current.values).toMatchObject({ search: 'K27', status: 'offline', page: 2 });

        // Back to the users URL: that page still resolves its own state.
        navigateTo('/users?search=rahim&status=active&page=4', users);
        expect(users.handle.current.values).toMatchObject({ search: 'rahim', status: 'active', page: 4 });
    });
});

describe('useQueryFilters — reset', () => {
    it('clears filters back to a bare path', () => {
        currentUrl = '/users?search=rahim&status=active&page=4';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.reset());

        expect(visits[0].url).toBe('/users');
    });

    it('clears the search draft as well as the URL', () => {
        currentUrl = '/users?search=rahim';
        const { handle } = mount({ defaults: DEFAULTS });

        act(() => handle.current.reset());

        expect(handle.current.draft.search).toBe('');
    });
});

describe('useQueryFilters — two hooks on one page', () => {
    it('a list hook committing keeps the page-level tab param', () => {
        currentUrl = '/employees?tab=departments';
        const { handle } = mount({ defaults: { search: '', status: 'all', page: 1 }, mode: 'client' });

        act(() => handle.current.set('status', 'active'));

        expect(visits[0].url).toBe('/employees?status=active&tab=departments');
    });

    it('a tab switch with replaceAll drops the previous tab\'s filters', () => {
        currentUrl = '/employees?tab=employees&search=rahim&status=active&page=3';
        const { handle } = mount({ defaults: { tab: 'employees' }, mode: 'client', debounceKeys: [] });

        act(() => handle.current.commit({ tab: 'departments' }, { replaceAll: true }));

        expect(visits[0].url).toBe('/employees?tab=departments');
    });
});

describe('useClampPage — a page that no longer exists', () => {
    function mountClamped(lastPage) {
        const handle = { current: null };
        const container = document.createElement('div');
        document.body.appendChild(container);
        const root = createRoot(container);

        function Probe({ last }) {
            handle.current = useQueryFilters({ defaults: DEFAULTS });
            useClampPage(handle.current, last);
            return null;
        }

        act(() => root.render(<Probe last={lastPage} />));
        return { handle, setLast: (last) => act(() => root.render(<Probe last={last} />)) };
    }

    it('snaps ?page=17 to the last real page, replacing the history entry', () => {
        currentUrl = '/users?page=17&status=active';
        mountClamped(4);

        expect(visits).toHaveLength(1);
        expect(visits[0].url).toBe('/users?page=4&status=active');
        expect(visits[0].replace).toBe(true);
    });

    it('does nothing while the page count is still unknown', () => {
        currentUrl = '/users?page=17';
        mountClamped(undefined);

        expect(visits).toHaveLength(0);
    });

    it('leaves a valid page alone', () => {
        currentUrl = '/users?page=3';
        mountClamped(4);

        expect(visits).toHaveLength(0);
    });

    it('goes to page 1 when the result set is empty', () => {
        currentUrl = '/users?page=3';
        mountClamped(0);

        expect(visits[0].url).toBe('/users');
    });
});

describe('useQueryFilters — client mode', () => {
    it('updates the URL without a server request, for pages that fetch their own rows', () => {
        const { handle } = mount({ defaults: DEFAULTS, mode: 'client' });

        act(() => handle.current.set('status', 'active'));

        expect(visits[0].kind).toBe('replace');
        expect(visits[0].url).toBe('/users?status=active');
        expect(visits[0].preserveState).toBe(true);
    });

    it('pagination pushes a history entry in client mode too', () => {
        const { handle } = mount({ defaults: DEFAULTS, mode: 'client' });

        act(() => handle.current.setPage(2));

        expect(visits[0].kind).toBe('push');
        expect(visits[0].url).toBe('/users?page=2');
    });
});
