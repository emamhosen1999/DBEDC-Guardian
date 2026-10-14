// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import Tree from '../Tree.jsx';
import Timeline from '../Timeline.jsx';

let root;
let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); });
function mount(ui) {
    host = document.createElement('div');
    document.body.appendChild(host);
    root = createRoot(host);
    act(() => root.render(ui));
}
const items = [
    { id: 'corridor', label: 'Corridor', children: [
        { id: 'ph1', label: 'Phase One', children: [{ id: 'ic', label: 'In-charge' }, { id: 'm1', label: 'Member' }] },
        { id: 'ph2', label: 'Phase Two' },
    ] },
];
const key = (el, k) => act(() => { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })); });
const item = (id) => [...host.querySelectorAll('[role="treeitem"]')].find((e) => e.getAttribute('aria-labelledby')?.endsWith(`-${id}`));

describe('Tree (WAI-ARIA tree pattern)', () => {
    it('exposes tree, treeitem and group roles with levels, sizes and expanded state', () => {
        mount(<Tree items={items} label="Teams" />);
        expect(host.querySelector('[role="tree"]').getAttribute('aria-label')).toBe('Teams');
        expect(item('corridor').getAttribute('aria-level')).toBe('1');
        expect(item('ph1').getAttribute('aria-level')).toBe('2');
        expect(item('ic').getAttribute('aria-level')).toBe('3');
        expect(item('ph1').getAttribute('aria-setsize')).toBe('2');
        expect(item('ph2').getAttribute('aria-posinset')).toBe('2');
        expect(item('ph1').getAttribute('aria-expanded')).toBe('true');
        expect(item('ph2').hasAttribute('aria-expanded')).toBe(false);
        expect(host.querySelectorAll('[role="group"]').length).toBe(2);
    });

    it('has a single tab stop (roving tabindex)', () => {
        mount(<Tree items={items} label="Teams" />);
        const stops = [...host.querySelectorAll('[role="treeitem"]')].filter((e) => e.tabIndex === 0);
        expect(stops).toHaveLength(1);
    });

    it('moves with the arrow keys, Home and End', () => {
        mount(<Tree items={items} label="Teams" />);
        const first = item('corridor');
        act(() => first.focus());
        key(first, 'ArrowDown');
        expect(document.activeElement).toBe(item('ph1'));
        key(document.activeElement, 'ArrowDown');
        expect(document.activeElement).toBe(item('ic'));
        key(document.activeElement, 'End');
        expect(document.activeElement).toBe(item('ph2'));
        key(document.activeElement, 'Home');
        expect(document.activeElement).toBe(item('corridor'));
        key(document.activeElement, 'ArrowUp');
        expect(document.activeElement).toBe(item('corridor'));
    });

    it('ArrowLeft closes an open branch, then moves to the parent; ArrowRight opens, then enters', () => {
        mount(<Tree items={items} label="Teams" />);
        const ph1 = item('ph1');
        act(() => ph1.focus());
        key(ph1, 'ArrowLeft');
        expect(item('ph1').getAttribute('aria-expanded')).toBe('false');
        expect(item('ic')).toBeUndefined();
        key(item('ph1'), 'ArrowRight');
        expect(item('ph1').getAttribute('aria-expanded')).toBe('true');
        key(item('ph1'), 'ArrowRight');
        expect(document.activeElement).toBe(item('ic'));
        key(item('ic'), 'ArrowLeft');
        expect(document.activeElement).toBe(item('ph1'));
    });

    it('Enter and Space select the focused item', () => {
        const onSelect = vi.fn();
        mount(<Tree items={items} label="Teams" onSelect={onSelect} />);
        const ph2 = item('ph2');
        act(() => ph2.focus());
        key(ph2, 'Enter');
        key(ph2, ' ');
        expect(onSelect).toHaveBeenCalledTimes(2);
        expect(onSelect.mock.calls[0][0].id).toBe('ph2');
    });
});

describe('Timeline', () => {
    it('lists events with their date, and says "Since records began" when there is none', () => {
        mount(<Timeline events={[{ id: 'a', date: '2026-01-02', title: 'Boundary moved' }, { id: 'b', date: null, title: 'First definition' }]} label="History" />);
        const rows = host.querySelectorAll('li');
        expect(rows).toHaveLength(2);
        expect(rows[1].textContent).toContain('Since records began');
        expect(host.querySelector('ol').getAttribute('aria-label')).toBe('History');
    });

    it('shows an honest empty message', () => {
        mount(<Timeline events={[]} empty="Nothing recorded yet." />);
        expect(host.textContent).toContain('Nothing recorded yet.');
    });
});
