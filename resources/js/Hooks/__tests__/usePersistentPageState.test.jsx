// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioural tests for remembered page state.
 *
 * Inertia's `useRemember` is mocked with a store keyed the same way the real one
 * is, so we can assert the two things that actually matter: that each page (and
 * each user) gets its own slot, and that state remembered by an older build
 * still reads correctly after new fields are added.
 */

const store = new Map();
let authUser = { id: 42 };

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useRemember: (initialState, key) => {
        const [state, setState] = React.useState(() =>
            store.has(key) ? store.get(key) : initialState,
        );
        React.useEffect(() => {
            store.set(key, state);
        }, [state, key]);
        return [state, setState];
    },
}));

const { usePersistentPageState } = await import('../usePersistentPageState');

function mount(pageKey, defaults, options) {
    const handle = { current: null };
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);

    function Probe() {
        handle.current = usePersistentPageState(pageKey, defaults, options);
        return null;
    }

    act(() => root.render(<Probe />));

    return { handle, unmount: () => act(() => root.unmount()) };
}

beforeEach(() => {
    store.clear();
    authUser = { id: 42 };
});

describe('usePersistentPageState', () => {
    it('starts from the defaults', () => {
        const { handle } = mount('Cameras/Index', { viewMode: 'table', expandedRows: [] });

        expect(handle.current[0]).toEqual({ viewMode: 'table', expandedRows: [] });
    });

    it('remembers a change and restores it on the next visit', () => {
        const first = mount('Cameras/Index', { viewMode: 'table' });
        act(() => first.handle.current[1]({ viewMode: 'grid' }));
        first.unmount();

        const second = mount('Cameras/Index', { viewMode: 'table' });

        expect(second.handle.current[0].viewMode).toBe('grid');
    });

    it('keeps each page independent', () => {
        const cameras = mount('Cameras/Index', { viewMode: 'table' });
        act(() => cameras.handle.current[1]({ viewMode: 'grid' }));
        cameras.unmount();

        const users = mount('Users/Index', { viewMode: 'table' });

        expect(users.handle.current[0].viewMode).toBe('table');
    });

    it('keeps each record independent when the key carries an id', () => {
        const a = mount('Users/Edit:42', { draftOpen: false });
        act(() => a.handle.current[1]({ draftOpen: true }));
        a.unmount();

        const b = mount('Users/Edit:55', { draftOpen: false });

        expect(b.handle.current[0].draftOpen).toBe(false);
    });

    it('does not leak state between signed-in users on a shared browser', () => {
        const first = mount('Cameras/Index', { viewMode: 'table' });
        act(() => first.handle.current[1]({ viewMode: 'grid' }));
        first.unmount();

        authUser = { id: 99 };
        const second = mount('Cameras/Index', { viewMode: 'table' });

        expect(second.handle.current[0].viewMode).toBe('table');
    });

    it('merges defaults over state remembered by an older build', () => {
        // Simulate history written before `viewMode` existed.
        store.set('ui:v1:user:42:Cameras/Index', { activeTab: 'overview' });

        const { handle } = mount('Cameras/Index', { activeTab: 'overview', viewMode: 'table' });

        expect(handle.current[0]).toEqual({ activeTab: 'overview', viewMode: 'table' });
    });

    it('accepts a functional update based on the previous state', () => {
        const { handle } = mount('Cameras/Index', { expandedRows: [] });

        act(() => handle.current[1]((prev) => ({ expandedRows: [...prev.expandedRows, 12] })));
        act(() => handle.current[1]((prev) => ({ expandedRows: [...prev.expandedRows, 19] })));

        expect(handle.current[0].expandedRows).toEqual([12, 19]);
    });

    it('resets every key back to its default', () => {
        const { handle } = mount('Cameras/Index', { viewMode: 'table', expandedRows: [] });

        act(() => handle.current[1]({ viewMode: 'grid', expandedRows: [1] }));
        act(() => handle.current[2]());

        expect(handle.current[0]).toEqual({ viewMode: 'table', expandedRows: [] });
    });

    it('keeps a stable object identity across renders so it is safe in dependency arrays', () => {
        const { handle } = mount('Cameras/Index', { viewMode: 'table' });
        const first = handle.current[0];

        act(() => handle.current[1]({ viewMode: 'table' })); // no actual change

        expect(handle.current[0]).toBe(first);
    });
});
